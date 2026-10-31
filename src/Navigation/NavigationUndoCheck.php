<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Action\Undo\UndoCheck;
use Frootbox\Core\Action\Undo\UndoConflict;
use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;

/**
 * Keeps at most one draft and one published state per navigation when
 * actions are undone in the wrong order (decision 0102).
 */
final readonly class NavigationUndoCheck implements UndoCheck
{
    public function __construct(private Revisions $revisions) {}

    public function check(array $changes): array
    {
        $names = [];

        foreach ($changes as $change) {
            $type = $change->newValues['type'] ?? $change->oldValues['type'] ?? null;
            $name = $change->newValues['entity_id'] ?? $change->oldValues['entity_id'] ?? null;

            if (Revisions::TABLE === $change->target && NavigationReader::TYPE === $type && \is_string($name)) {
                $names[$name] = true;
            }
        }

        $conflicts = [];

        foreach (array_map(strval(...), array_keys($names)) as $name) {
            foreach ([RevisionStatus::Draft, RevisionStatus::Published] as $status) {
                $found = $this->revisions->fetch(['where' => ['type' => NavigationReader::TYPE, 'entityId' => $name, 'status' => $status]])->all();

                if (\count($found) > 1) {
                    $conflicts[] = new UndoConflict(
                        \sprintf('The navigation "%s" would have %d %s revisions.', $name, \count($found), $status->value),
                        [Revisions::TABLE => array_map(static fn(Revision $revision): int => $revision->id, $found)],
                    );
                }
            }
        }

        return $conflicts;
    }
}
