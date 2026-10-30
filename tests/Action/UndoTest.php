<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Action;

use DateTimeImmutable;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\Compensations;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Action\Undo\Undoer;
use Frootbox\Core\Action\UndoException;
use Frootbox\Core\Database\Rows;
use Frootbox\Core\Database\TableWriter;
use Frootbox\Core\Database\WriteGuard\GuardedConnection;
use Frootbox\Core\Database\WriteGuard\GuardedDriver;
use Frootbox\Core\Database\WriteGuard\GuardedStatement;
use Frootbox\Core\Database\WriteGuard\WriteGuard;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNotes;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNoteWithSql;
use Frootbox\Core\Tests\Fixtures\Notes\DeleteNote;
use Frootbox\Core\Tests\Fixtures\Notes\MailNote;
use Frootbox\Core\Tests\Fixtures\Notes\Outbox;
use Frootbox\Core\Tests\Fixtures\Notes\RenameNote;
use Frootbox\Core\Tests\Fixtures\Notes\ShareNote;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Undoer::class)]
#[CoversClass(UndoAction::class)]
#[CoversClass(Compensations::class)]
#[CoversClass(Journal::class)]
#[CoversClass(ActionLog::class)]
#[CoversClass(TableWriter::class)]
#[CoversClass(Rows::class)]
#[CoversClass(WriteGuard::class)]
#[CoversClass(GuardedDriver::class)]
#[CoversClass(GuardedConnection::class)]
#[CoversClass(GuardedStatement::class)]
#[CoversClass(Runner::class)]
final class UndoTest extends NotesTestCase
{
    public function testUndoesAnInsert(): void
    {
        $this->runner()->run(new CreateNote('Hello'));

        $result = $this->undo($this->lastEntry()->id);

        self::assertSame(['action' => 'note.create', 'changes' => 1], $result);
        self::assertSame(0, $this->notes()->count());
    }

    public function testUndoesAnUpdate(): void
    {
        $id = $this->runner()->run(new CreateNote('Old title'))->id;
        $this->runner()->run(new RenameNote($id, 'New title'));

        $this->undo($this->lastEntry()->id);

        self::assertSame('Old title', $this->notes()->getById($id)->title);
    }

    public function testUndoesADeleteWithTheSameId(): void
    {
        $note = $this->runner()->run(new CreateNote('Keep me', tags: ['x']));
        $this->runner()->run(new DeleteNote($note->id));
        self::assertNull($this->notes()->fetchById($note->id));

        $this->undo($this->lastEntry()->id);

        $restored = $this->notes()->getById($note->id);
        self::assertSame('Keep me', $restored->title);
        self::assertSame(['x'], $restored->tags);
        self::assertEquals($note->createdAt, $restored->createdAt);
    }

    public function testUndoingAnUndoRedoesTheAction(): void
    {
        $id = $this->runner()->run(new CreateNote('Old'))->id;
        $this->runner()->run(new RenameNote($id, 'New'));
        $rename = $this->lastEntry()->id;

        $this->undo($rename);
        $undo = $this->lastEntry()->id;
        self::assertSame('Old', $this->notes()->getById($id)->title);
        self::assertSame($undo, $this->log()->find($rename)?->undoneBy);

        $this->undo($undo);
        self::assertSame('New', $this->notes()->getById($id)->title);
        self::assertNull($this->log()->find($rename)?->undoneBy);

        // The redone rename can be undone again.
        $this->undo($rename);
        self::assertSame('Old', $this->notes()->getById($id)->title);
    }

    public function testRefusesWhenTheDataChangedSince(): void
    {
        $id = $this->runner()->run(new CreateNote('One'))->id;
        $this->runner()->run(new RenameNote($id, 'Two'));
        $first = $this->lastEntry()->id;
        $this->runner()->run(new RenameNote($id, 'Three'));

        $this->expectException(UndoException::class);
        $this->expectExceptionMessage('notes #' . $id);

        try {
            $this->undo($first);
        } finally {
            self::assertSame('Three', $this->notes()->getById($id)->title);
        }
    }

    public function testUndoesNestedActionsTogether(): void
    {
        $this->runner()->run(new CreateNotes(['One', 'Two', 'Three']));
        $entries = $this->log()->recent(4);
        $outer = $entries[3];

        self::assertSame(3, $this->notes()->count());
        $this->undo($outer->id);
        self::assertSame(0, $this->notes()->count());
    }

    public function testNestedActionsCannotBeUndoneAlone(): void
    {
        $this->runner()->run(new CreateNotes(['One']));

        $this->expectException(UndoException::class);
        $this->expectExceptionMessage('ran inside action');

        $this->undo($this->lastEntry()->id);
    }

    public function testWritesPastTheRepositoryMakeAnActionNotUndoable(): void
    {
        $this->runner()->run(new CreateNoteWithSql('Raw'));

        self::assertFalse($this->lastEntry()->undoable);
        self::assertSame(1, $this->notes()->count());

        $this->expectException(UndoException::class);
        $this->undo($this->lastEntry()->id);
    }

    public function testActionsWithOutsideEffectsAreNotUndoable(): void
    {
        $id = $this->runner()->run(new CreateNote('Hello'))->id;
        $this->runner()->run(new MailNote($id));

        self::assertFalse($this->lastEntry()->undoable);
    }

    public function testCompensationsRunWhenUndoing(): void
    {
        $id = $this->runner()->run(new CreateNote('Hello'))->id;
        $this->runner()->run(new ShareNote($id));
        $share = $this->lastEntry();

        self::assertTrue($share->undoable);
        self::assertTrue($this->notes()->getById($id)->pinned);

        $this->undo($share->id);

        self::assertFalse($this->notes()->getById($id)->pinned);
        self::assertSame(['shared #' . $id, 'unshared #' . $id], Outbox::$messages);

        // The undo ran a compensation that cannot be undone, so redo is not possible.
        self::assertFalse($this->lastEntry()->undoable);
    }

    public function testCannotUndoTwiceOrFailedActions(): void
    {
        $this->runner()->run(new CreateNote('Hello'));
        $create = $this->lastEntry()->id;
        $this->undo($create);

        try {
            $this->undo($create);
            self::fail('Expected the second undo to fail.');
        } catch (UndoException $error) {
            self::assertStringContainsString('already undone', $error->getMessage());
        }

        $this->expectException(UndoException::class);
        $this->expectExceptionMessage('no such entry');
        $this->undo(999999);
    }

    public function testUndoNeedsThePermissionOfTheOriginalAction(): void
    {
        $this->runner()->run(new CreateNote('Hello'));

        $this->expectException(AccessDeniedException::class);

        $this->runner()->run(new UndoAction($this->lastEntry()->id), Context::guest());
    }

    public function testPruningForgetsOldChanges(): void
    {
        $this->runner()->run(new CreateNote('Hello'));
        $entry = $this->lastEntry();

        $tomorrow = new DateTimeImmutable('+1 day');
        $deleted = $this->log()->prune($tomorrow, new DateTimeImmutable('-1 year'));

        self::assertSame(['changes' => 1, 'entries' => 0], $deleted);
        self::assertFalse($this->log()->find($entry->id)?->undoable);

        $this->log()->prune($tomorrow, $tomorrow);
        self::assertNull($this->log()->find($entry->id));
    }

    /**
     * @return array{action: string, changes: int}
     */
    private function undo(int $id): array
    {
        return $this->runner()->run(new UndoAction($id));
    }

    private function log(): ActionLog
    {
        return $this->get(ActionLog::class);
    }
}
