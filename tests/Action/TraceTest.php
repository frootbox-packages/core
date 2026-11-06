<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Action;

use DateTimeImmutable;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\ActionLogPage;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\EntryStatus;
use Frootbox\Core\Action\ListActionLog;
use Frootbox\Core\Action\LogEntry;
use Frootbox\Core\Action\PruneOnSchedule;
use Frootbox\Core\Action\RowDiff;
use Frootbox\Core\Auth\AccessToken;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\ActorType;
use Frootbox\Core\Auth\Device;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Database\MigrationJournal;
use Frootbox\Core\Database\MigrationsFactory;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\Schedule\ScheduleRan;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Fixtures\Notes\DeleteNote;
use Frootbox\Core\Tests\Fixtures\Notes\RenameNote;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Frootbox\Database\Migration;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * Token, device and migrations in the log of actions (decision 0174).
 */
#[CoversClass(ActionLog::class)]
#[CoversClass(MigrationJournal::class)]
#[CoversClass(RowDiff::class)]
#[CoversClass(PruneOnSchedule::class)]
#[CoversClass(ListActionLog::class)]
#[CoversClass(ActionLogPage::class)]
#[CoversClass(Migrator::class)]
#[CoversClass(Migration::class)]
#[CoversClass(Device::class)]
#[CoversClass(AccessToken::class)]
final class TraceTest extends NotesTestCase
{
    private const string IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    public function testTheLogKeepsTokenAndDevice(): void
    {
        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $tokens = $this->get(AccessTokens::class);
        $session = $tokens->issue($user->id, TokenType::Session, self::IPHONE);
        $api = $tokens->issue($user->id, TokenType::Api, 'Claude Desktop');

        self::assertSame('Safari · iPhone', $session->accessToken->device);
        self::assertSame('Safari · iPhone', $tokens->authenticate($session->token)?->token->label());
        self::assertSame('Claude Desktop', $api->accessToken->label(), 'An API token is told apart by its name.');

        $context = new Context(Actor::system(), Channel::Web, $session->accessToken->id, device: 'Safari · iPhone');
        $this->get(CurrentContext::class)->runAs($context, fn(): mixed => $this->runner()->run(new CreateNote('From the phone')));

        $entry = $this->lastEntry();
        self::assertSame($session->accessToken->id, $entry->tokenId);
        self::assertSame('Safari · iPhone', $entry->device);

        $tokens->revoke($session->accessToken->id);
        self::assertSame('Safari · iPhone', $this->get(ActionLog::class)->find($entry->id)?->device, 'The device stays when the token is gone.');
    }

    public function testEveryMigrationLeavesAnEntry(): void
    {
        $log = $this->get(ActionLog::class);
        $ids = $this->connection()->fetchFirstColumn('SELECT id FROM action_log WHERE action = ? ORDER BY id', [ActionLog::MIGRATE]);
        $entries = array_filter(array_map(static fn(mixed $id): ?LogEntry => $log->find(is_numeric($id) ? (int) $id : 0), $ids));
        $core = array_values(array_filter($entries, static fn(LogEntry $entry): bool => 'Frootbox\Core\Migrations\Version20261222090000' === ($entry->input['version'] ?? null)));

        self::assertCount(1, $core);
        self::assertSame(ActorType::System->value, $core[0]->actorType);
        self::assertSame(Channel::Console->value, $core[0]->channel);
        self::assertSame(EntryStatus::Succeeded, $core[0]->status);
        self::assertSame(['package' => 'frootbox/core', 'version' => 'Frootbox\Core\Migrations\Version20261222090000', 'description' => 'Adds action_log.token_id, action_log.device, access_tokens.device and action_changes.shortened'], $core[0]->input);
        $ran = $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . MigrationsFactory::TABLE);
        self::assertCount(is_numeric($ran) ? (int) $ran : -1, $entries, 'One entry for each migration, the first one too.');
    }

    public function testRewrittenRowsAreChangesOfTheMigration(): void
    {
        $this->runner()->run(new CreateNote('Quiet'));
        $this->runner()->run(new CreateNote('LOUD'));
        $this->get(MigrationSources::class)->add('Frootbox\Core\Tests\Fixtures\History\Rewrite', \dirname(__DIR__) . '/Fixtures/History/Rewrite', 'acme/notes');

        self::assertSame(['Frootbox\Core\Tests\Fixtures\History\Rewrite\Version20991231000000'], $this->get(Migrator::class)->migrate());

        $entry = $this->lastEntry();
        self::assertSame(ActionLog::MIGRATE, $entry->action);
        self::assertSame(['package' => 'acme/notes', 'version' => 'Frootbox\Core\Tests\Fixtures\History\Rewrite\Version20991231000000', 'description' => 'Writes the titles of notes in capitals'], $entry->input);

        $changes = $this->get(ActionLog::class)->changes($entry->id);
        self::assertCount(1, $changes, 'Only the row that changed.');
        self::assertSame('notes', $changes[0]->target);
        self::assertSame(['title' => 'Quiet'], $changes[0]->oldValues);
        self::assertSame(['title' => 'QUIET'], $changes[0]->newValues);
        self::assertSame(['QUIET', 'LOUD'], $this->connection()->fetchFirstColumn('SELECT title FROM notes ORDER BY id'));
    }

    public function testAFailedMigrationIsLoggedToo(): void
    {
        $this->get(MigrationSources::class)->add('Frootbox\Core\Tests\Fixtures\History\Failing', \dirname(__DIR__) . '/Fixtures/History/Failing');

        try {
            $this->get(Migrator::class)->migrate();
            self::fail('The migration did not fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Broken on purpose.', $error->getMessage());
        }

        $entry = $this->lastEntry();
        self::assertSame(ActionLog::MIGRATE, $entry->action);
        self::assertSame(EntryStatus::Failed, $entry->status);
        self::assertStringContainsString('Broken on purpose.', (string) $entry->error);
    }

    public function testOldChangesKeepWhatChangedAndWhatWasLost(): void
    {
        $note = $this->runner()->run(new CreateNote('Hello', body: 'Long text', tags: ['a', 'b']));
        $created = $this->lastEntry()->id;
        $this->runner()->run(new RenameNote($note->id, 'Hello there'));
        $renamed = $this->lastEntry()->id;
        $this->runner()->run(new DeleteNote($note->id));
        $deleted = $this->lastEntry()->id;

        $log = $this->get(ActionLog::class);
        self::assertSame(['shortened' => 3, 'entries' => 0], $log->prune(new DateTimeImmutable('+1 minute'), new DateTimeImmutable('-1 year')));

        $insert = $log->changes($created)[0];
        self::assertTrue($insert->shortened);
        self::assertSame(['notes', (string) $note->id, null], [$insert->target, $insert->rowId, $insert->newValues], 'Of an insert only table and id.');

        $update = $log->changes($renamed)[0];
        self::assertSame(['title' => 'Hello'], array_diff_key($update->oldValues ?? [], ['updated_at' => true]), 'Only what changed.');
        self::assertSame('Hello there', $update->newValues['title'] ?? null);

        $delete = $log->changes($deleted)[0];
        self::assertSame('Long text', $delete->oldValues['body'] ?? null, 'The lost content stays.');
        self::assertFalse($log->find($deleted)?->undoable);
    }

    public function testTheSchedulerPrunesOnceAnHour(): void
    {
        $this->runner()->run(new CreateNote('Hello'));
        $entry = $this->lastEntry()->id;
        $this->connection()->update('action_log', ['created_at' => '2000-01-01 00:00:00'], ['id' => $entry]);

        $this->get(PruneOnSchedule::class)(new ScheduleRan(new DateTimeImmutable()));
        self::assertNull($this->get(ActionLog::class)->find($entry), 'Older than a year.');

        $this->runner()->run(new CreateNote('Again'));
        $again = $this->lastEntry()->id;
        $this->connection()->update('action_log', ['created_at' => '2000-01-01 00:00:00'], ['id' => $again]);
        $this->get(PruneOnSchedule::class)(new ScheduleRan(new DateTimeImmutable('+10 minutes')));
        self::assertNotNull($this->get(ActionLog::class)->find($again), 'Not again within the hour.');
    }

    public function testJsonColumnsKeepOnlyTheChangedPaths(): void
    {
        $before = ['id' => 1, 'title' => 'Page', 'content' => json_encode(['areas' => ['main' => [
            ['id' => 'a1', 'block' => 'Hero', 'fields' => ['title' => 'Welcome', 'text' => 'Hi']],
            ['id' => 'b2', 'block' => 'Text', 'fields' => ['text' => 'Old']],
            ['id' => 'c/3', 'block' => 'Faq', 'fields' => ['items' => [['q' => 'Why?'], ['q' => 'How?']]]],
        ]]], \JSON_THROW_ON_ERROR)];
        $after = ['id' => 1, 'title' => 'Page', 'content' => json_encode(['areas' => ['main' => [
            ['id' => 'c/3', 'block' => 'Faq', 'fields' => ['items' => [['q' => 'Why?'], ['q' => 'When?']]]],
            ['id' => 'a1', 'block' => 'Hero', 'fields' => ['title' => 'Welcome!', 'text' => 'Hi']],
        ]]], \JSON_THROW_ON_ERROR)];

        [$old, $new] = RowDiff::of($before, $after);

        self::assertSame(['content'], array_keys($old), 'Unchanged columns are left out.');
        self::assertSame([
            'areas/main/#' => ['a1', 'b2', 'c/3'],
            'areas/main/#a1/fields/title' => 'Welcome',
            'areas/main/#b2' => ['id' => 'b2', 'block' => 'Text', 'fields' => ['text' => 'Old']],
            'areas/main/#c~13/fields/items/1/q' => 'How?',
        ], $old['content']);
        self::assertSame([
            'areas/main/#' => ['c/3', 'a1'],
            'areas/main/#a1/fields/title' => 'Welcome!',
            'areas/main/#c~13/fields/items/1/q' => 'When?',
        ], $new['content'], 'The removed block is only on the old side.');

        self::assertSame('How?', RowDiff::at($before['content'], 'areas/main/#c~13/fields/items/1/q'));
        self::assertNull(RowDiff::at($after['content'], 'areas/main/#b2'));
    }

    public function testTheWholeLogWithFilters(): void
    {
        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new CreateNote('One'));
        $this->get(CurrentContext::class)->runAs(new Context(Actor::user($user->id, 'Jane'), Channel::Api, device: 'Claude Desktop'), function (): void {
            try {
                $this->runner()->run(new CreateNote('Two'));
            } catch (AccessDeniedException) {
            }
        });

        $all = $this->runner()->ask(new ListActionLog(limit: 2));
        self::assertCount(2, $all->entries);
        self::assertNotNull($all->next);

        $jane = $this->runner()->ask(new ListActionLog(user: $user->id))->entries;
        self::assertCount(1, $jane);
        self::assertSame([EntryStatus::Denied, Channel::Api->value, 'Claude Desktop'], [$jane[0]->status, $jane[0]->channel, $jane[0]->device]);

        self::assertSame(['note.create', 'note.create'], array_map(static fn(LogEntry $entry): string => $entry->action, $this->runner()->ask(new ListActionLog(action: 'note.*'))->entries));
        self::assertCount(1, $this->runner()->ask(new ListActionLog(action: 'note.create', status: EntryStatus::Succeeded))->entries);
        self::assertSame([], $this->runner()->ask(new ListActionLog(from: new DateTimeImmutable('+1 hour')))->entries);
        self::assertNotEmpty($this->runner()->ask(new ListActionLog(action: ActionLog::MIGRATE, channel: Channel::Console))->entries);

        $this->expectException(AccessDeniedException::class);
        $this->runner()->ask(new ListActionLog(), new Context(Actor::user($user->id, 'Jane'), Channel::Web));
    }

    public function testTheLogWorksBeforeTheLaterColumnsExist(): void
    {
        $this->connection()->executeStatement('ALTER TABLE action_log DROP COLUMN device');

        $this->runner()->run(new CreateNote('Updated, not migrated'));

        self::assertSame('note.create', $this->lastEntry()->action);
    }
}
