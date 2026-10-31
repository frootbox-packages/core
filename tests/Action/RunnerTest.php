<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Action;

use DateTimeImmutable;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\ActionExecuted;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Action\EntryStatus;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Normalizer;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Action\Schema\Fields;
use Frootbox\Core\Action\Schema\InputMapper;
use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Action\Schema\SchemaFactory;
use Frootbox\Core\Action\UnknownActionException;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Auth\RolePermissions;
use Frootbox\Core\Database\RecordingWriter;
use Frootbox\Core\Event\ListenerProvider;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNotes;
use Frootbox\Core\Tests\Fixtures\Notes\FailingNote;
use Frootbox\Core\Tests\Fixtures\Notes\ListNotes;
use Frootbox\Core\Tests\Fixtures\Notes\Note;
use Frootbox\Core\Tests\Fixtures\Notes\NoteStatus;
use Frootbox\Core\Tests\Fixtures\Notes\WriteInQuery;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Database\Result;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(Runner::class)]
#[CoversClass(ActionLog::class)]
#[CoversClass(Journal::class)]
#[CoversClass(Catalog::class)]
#[CoversClass(Descriptor::class)]
#[CoversClass(InputMapper::class)]
#[CoversClass(InputValidator::class)]
#[CoversClass(Fields::class)]
#[CoversClass(SchemaFactory::class)]
#[CoversClass(Normalizer::class)]
#[CoversClass(CurrentContext::class)]
#[CoversClass(Context::class)]
#[CoversClass(Actor::class)]
#[CoversClass(Gate::class)]
#[CoversClass(RolePermissions::class)]
#[CoversClass(RecordingWriter::class)]
final class RunnerTest extends NotesTestCase
{
    public function testRunsAnActionAndLogsIt(): void
    {
        $note = $this->runner()->run(new CreateNote('Hello', priority: 3, tags: ['a', 'b']));

        self::assertSame('Hello', $note->title);
        self::assertSame(3, $note->priority);
        self::assertSame(['a', 'b'], $note->tags);
        self::assertSame(NoteStatus::Draft, $note->status);

        $entry = $this->lastEntry();
        self::assertSame('note.create', $entry->action);
        self::assertSame(EntryStatus::Succeeded, $entry->status);
        self::assertSame('console', $entry->channel);
        self::assertSame('system', $entry->actorType);
        self::assertSame('Hello', $entry->input['title'] ?? null);
        self::assertTrue($entry->undoable);
        self::assertNull($entry->parentId);
    }

    public function testCallsByNameWithPlainInput(): void
    {
        $note = $this->runner()->call('note.create', [
            'title' => 'From the API',
            'status' => 'published',
            'publishedAt' => '2026-10-30T12:00:00+00:00',
        ]);

        self::assertInstanceOf(Note::class, $note);
        self::assertSame(NoteStatus::Published, $note->status);
        self::assertSame('2026-10-30 12:00:00', $note->publishedAt?->format('Y-m-d H:i:s'));

        $list = $this->runner()->call('note.list', ['status' => 'published']);
        self::assertInstanceOf(Result::class, $list);
        self::assertCount(1, $list);
    }

    public function testRejectsInvalidInputAndLogsIt(): void
    {
        try {
            $this->runner()->call('note.create', ['title' => '', 'priority' => 11, 'color' => 'red']);
            self::fail('Expected invalid input.');
        } catch (InvalidInputException $error) {
            self::assertSame('note.create', $error->name);
            self::assertSame('Unknown fields: color', $error->errors['/'] ?? null);
            self::assertArrayHasKey('/title', $error->errors);
            self::assertArrayHasKey('/priority', $error->errors);
        }

        self::assertSame(EntryStatus::Invalid, $this->lastEntry()->status);
        self::assertSame(0, $this->notes()->count());
    }

    public function testChecksAttributesAlsoForTypedCalls(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('/priority');

        $this->runner()->run(new CreateNote('Hello', priority: 99));
    }

    public function testGuestsMayNotRunProtectedActions(): void
    {
        try {
            $this->runner()->run(new CreateNote('Hello'), Context::guest());
            self::fail('Expected access denied.');
        } catch (AccessDeniedException $error) {
            self::assertStringContainsString('note.create', $error->getMessage());
        }

        $entry = $this->lastEntry();
        self::assertSame(EntryStatus::Denied, $entry->status);
        self::assertSame('guest', $entry->actorType);
        self::assertSame('web', $entry->channel);
    }

    public function testPublicActionsAreOpenAndSecretsAreMasked(): void
    {
        $result = $this->runner()->call('note.subscribe', ['email' => 'jan@example.com', 'password' => 'very secret'], Context::guest());

        self::assertSame('subscribed jan@example.com', $result);
        self::assertSame(['email' => 'jan@example.com', 'password' => InputMapper::MASK], $this->lastEntry()->input);
    }

    public function testValidatesFormats(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->runner()->call('note.subscribe', ['email' => 'no mail', 'password' => 'short'], Context::guest());
    }

    public function testFailedActionsLeaveNothingBehind(): void
    {
        try {
            $this->runner()->run(new FailingNote('Ghost'));
            self::fail('Expected the action to fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Something broke', $error->getMessage());
        }

        self::assertSame(0, $this->notes()->count());

        $entry = $this->lastEntry();
        self::assertSame(EntryStatus::Failed, $entry->status);
        self::assertStringContainsString('Something broke', (string) $entry->error);
        self::assertSame([], $this->get(ActionLog::class)->changes($entry->id));
    }

    public function testNestedActionsAreLoggedWithTheirParent(): void
    {
        $ids = $this->runner()->run(new CreateNotes(['One', 'Two']));

        self::assertCount(2, $ids);

        $entries = $this->get(ActionLog::class)->recent(3);
        self::assertSame(['note.create-many', 'note.create', 'note.create'], array_map(static fn($entry): string => $entry->action, array_reverse($entries)));
        self::assertSame($entries[2]->id, $entries[0]->parentId);
        self::assertTrue($entries[2]->undoable);
        self::assertFalse($entries[0]->undoable);
        self::assertCount(2, $this->get(ActionLog::class)->changes($entries[2]->id));
    }

    public function testFiresActionExecuted(): void
    {
        $seen = [];
        $this->get(ListenerProvider::class)->listen(ActionExecuted::class, static function (ActionExecuted $event) use (&$seen): void {
            $seen[] = $event->descriptor->name . ' #' . $event->entryId;
        });

        $this->runner()->run(new CreateNote('Hello'));

        self::assertSame(['note.create #' . $this->lastEntry()->id], $seen);
    }

    public function testQueriesAreNotLogged(): void
    {
        $this->runner()->run(new CreateNote('Hello'));
        $before = $this->lastEntry()->id;

        $notes = $this->runner()->ask(new ListNotes(), Context::guest(Channel::Api));

        self::assertCount(1, $notes);
        self::assertSame($before, $this->lastEntry()->id);
    }

    public function testDataCanOnlyBeChangedInsideActions(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside an action');

        $this->runner()->ask(new WriteInQuery());
    }

    public function testUnknownNames(): void
    {
        $this->expectException(UnknownActionException::class);

        $this->runner()->call('note.fly', []);
    }

    public function testResultsNormalizeToPlainValues(): void
    {
        $note = $this->runner()->run(new CreateNote('Hello', publishedAt: new DateTimeImmutable('2026-10-30 08:00:00')));

        $plain = Normalizer::normalize($note);

        self::assertIsArray($plain);
        self::assertSame('Hello', $plain['title']);
        self::assertSame('draft', $plain['status']);
        self::assertIsString($plain['publishedAt']);
        self::assertStringStartsWith('2026-10-30T08:00:00', $plain['publishedAt']);
    }
}
