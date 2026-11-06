<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Database\Entity;

/**
 * A row an action changed, with its state before and after. For
 * ChangeKind::Action, target is the name of the compensating action and
 * newValues its input. Once the action can no longer be undone, the
 * change is shortened (decision 0174): an update keeps only what changed,
 * a JSON column as paths (RowDiff), an insert only table and id.
 */
final readonly class Change extends Entity
{
    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function __construct(
        public int $id,
        public int $entryId,
        public ChangeKind $kind,
        public string $target,
        public ?string $rowId,
        public ?array $oldValues,
        public ?array $newValues,
        public bool $shortened = false,
    ) {}
}
