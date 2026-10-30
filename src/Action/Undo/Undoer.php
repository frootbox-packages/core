<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Undo;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Change;
use Frootbox\Core\Action\ChangeKind;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\EntryStatus;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\LogEntry;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Action\Schema\InputMapper;
use Frootbox\Core\Action\UndoException;
use Frootbox\Core\Auth\Permissions;
use Frootbox\Core\Database\Rows;
use Frootbox\Core\Database\TableWriter;
use LogicException;

/**
 * Undoes an action from the log: applies its recorded changes in reverse
 * and runs its compensations. Called by UndoAction, so undoing is an
 * action itself and can be undone again.
 */
final readonly class Undoer
{
    public function __construct(
        private ActionLog $log,
        private TableWriter $writer,
        private Journal $journal,
        private Catalog $catalog,
        private Runner $runner,
        private Permissions $permissions,
        private CurrentContext $context,
    ) {}

    /**
     * @return array{action: string, changes: int}
     */
    public function undo(int $entryId): array
    {
        $current = $this->journal->currentEntryId() ?? throw new LogicException('Undo with the action action.undo, not directly.');
        $entry = $this->log->find($entryId) ?? throw UndoException::notPossible($entryId, 'there is no such entry.');
        $this->assertUndoable($entry);

        $changes = array_reverse($this->log->changes($entryId));
        $this->assertUnchanged($entryId, $changes);

        foreach ($changes as $change) {
            $this->revert($change);
        }

        $this->writer->update(ActionLog::ENTRIES, $entryId, ['undone_by' => $current]);

        return ['action' => $entry->action, 'changes' => \count($changes)];
    }

    private function assertUndoable(LogEntry $entry): void
    {
        $reason = match (true) {
            null !== $entry->parentId => \sprintf('it ran inside action #%d. Undo that one.', $entry->parentId),
            EntryStatus::Succeeded !== $entry->status => 'it did not succeed.',
            null !== $entry->undoneBy => \sprintf('it was already undone by #%d.', $entry->undoneBy),
            !$entry->undoable => 'it wrote data past the repositories, has effects outside Frootbox, or its changes were pruned.',
            !$this->catalog->has($entry->action) => \sprintf('the action "%s" no longer exists.', $entry->action),
            default => null,
        };

        if (null !== $reason) {
            throw UndoException::notPossible($entry->id, $reason);
        }

        // Undoing needs the permission of the original action, too.
        $original = $this->catalog->get($entry->action);
        $actor = $this->context->get()->actor;

        if (!$original->public && !$this->permissions->allows($actor, $original->permission)) {
            throw AccessDeniedException::forPermission($original->permission, $actor);
        }
    }

    /**
     * Refuses if a row was changed again later, so no newer work is lost.
     *
     * @param list<Change> $changes
     */
    private function assertUnchanged(int $entryId, array $changes): void
    {
        $conflicts = [];

        foreach ($changes as $change) {
            if (ChangeKind::Action === $change->kind || null === $change->rowId) {
                continue;
            }

            $expected = ChangeKind::Delete === $change->kind ? null : $change->newValues;

            if (!Rows::same($this->writer->find($change->target, $change->rowId), $expected)) {
                $conflicts[] = \sprintf('%s #%s', $change->target, $change->rowId);
            }
        }

        if ([] !== $conflicts) {
            throw UndoException::conflict($entryId, array_values(array_unique($conflicts)));
        }
    }

    private function revert(Change $change): void
    {
        if (ChangeKind::Action === $change->kind) {
            $this->compensate($change);

            return;
        }

        $rowId = $change->rowId ?? throw new LogicException('A recorded row change has no row id.');

        match ($change->kind) {
            ChangeKind::Insert => $this->writer->delete($change->target, $rowId),
            ChangeKind::Update => $this->writer->update($change->target, $rowId, $change->oldValues ?? []),
            ChangeKind::Delete => $this->writer->insert($change->target, $change->oldValues ?? []),
        };
    }

    private function compensate(Change $change): void
    {
        $descriptor = $this->catalog->get($change->target);
        $action = InputMapper::toObject($descriptor->class, $change->newValues ?? []);

        if (!$action instanceof Action) {
            throw new DefinitionException(\sprintf('The compensation %s is not an action.', $descriptor->class));
        }

        $this->runner->run($action);
    }
}
