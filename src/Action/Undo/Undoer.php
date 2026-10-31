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
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Auth\Hierarchy;
use Frootbox\Core\Database\RecordingWriter;
use Frootbox\Core\Database\SensitiveColumns;
use Frootbox\Database\Rows;
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
        private RecordingWriter $writer,
        private Journal $journal,
        private Catalog $catalog,
        private Runner $runner,
        private Gate $gate,
        private Hierarchy $hierarchy,
        private SensitiveColumns $sensitive,
        private CurrentContext $context,
        private UndoChecks $checks,
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

        $this->assertConsistent($entryId, array_reverse($changes));

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

        if (!$this->gate->allows($actor, $original)) {
            throw AccessDeniedException::forPermission($original->permission, $actor);
        }

        // Nobody undoes the work of someone who ranks above them.
        if (!$this->hierarchy->mayUndoActionsOf($entry->actorType, $entry->actorId)) {
            throw new AccessDeniedException(\sprintf('Action #%d was run by %s, who ranks above you.', $entry->id, $entry->actorName ?? $entry->actorType));
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
        $checked = [];

        // $changes runs from the newest to the oldest. A row changed several
        // times by the action must match its last change only.
        foreach ($changes as $change) {
            if (ChangeKind::Action === $change->kind || null === $change->rowId || isset($checked[$change->target][$change->rowId])) {
                continue;
            }

            $checked[$change->target][$change->rowId] = true;

            $expected = ChangeKind::Delete === $change->kind ? null : $change->newValues;

            $current = $this->sensitive->mask($change->target, $this->writer->find($change->target, $change->rowId));

            if (!Rows::same($current, $expected)) {
                $conflicts[] = \sprintf('%s #%s', $change->target, $change->rowId);
            }
        }

        if ([] !== $conflicts) {
            throw UndoException::conflict($entryId, array_values(array_unique($conflicts)));
        }
    }

    /**
     * Runs the undo checks on the reverted data; on a conflict the whole
     * undo is rolled back with the action. Names the later actions in the
     * way, so they can be undone first.
     *
     * @param list<Change> $changes
     */
    private function assertConsistent(int $entryId, array $changes): void
    {
        $reasons = [];

        foreach ($this->checks->conflicts($changes) as $conflict) {
            $later = array_map(
                static fn(LogEntry $entry): string => \sprintf('#%d (%s)', $entry->id, $entry->action),
                $this->log->laterChangesOf($conflict->rows, $entryId, $this->journal->rootEntryId()),
            );

            $reasons[] = [] === $later
                ? $conflict->message
                : \sprintf('%s Undo %s first.', $conflict->message, self::listing($later));
        }

        if ([] !== $reasons) {
            throw UndoException::blocked($entryId, array_values(array_unique($reasons)));
        }
    }

    /**
     * @param non-empty-list<string> $items
     */
    private static function listing(array $items): string
    {
        $last = array_pop($items);

        return [] === $items ? $last : implode(', ', $items) . ' and ' . $last;
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
            // Sensitive columns were recorded as "***": keep them on update,
            // leave them empty when restoring a deleted row.
            ChangeKind::Update => $this->writer->update($change->target, $rowId, $this->sensitive->without($change->target, $change->oldValues ?? [])),
            ChangeKind::Delete => $this->writer->insert($change->target, $this->sensitive->cleared($change->target, $change->oldValues ?? [])),
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
