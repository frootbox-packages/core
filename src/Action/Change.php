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
 * newValues its input.
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
    ) {}
}
