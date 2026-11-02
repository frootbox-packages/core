<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Undo;

use Frootbox\Core\Action\Change;

/**
 * Checks rules across several rows after an action was undone, e.g. "a
 * page has at most one draft" (decision 0102). Undoing compares single
 * rows only; this catches what undoing in the wrong order would break.
 *
 *     $registry->undoCheck(OrderNumbersCheck::class);
 *
 * It runs inside the transaction of the undo, sees the data as it would
 * be and must not change anything.
 */
interface UndoCheck
{
    /**
     * @param list<Change> $changes the changes that were undone, in the order they first happened
     *
     * @return list<UndoConflict>
     */
    public function check(array $changes): array;
}
