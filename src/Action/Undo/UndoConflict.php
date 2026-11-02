<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Undo;

/**
 * A rule an undo would break. The rows are the ones in the way, e.g. the
 * other draft; the later actions that changed them are named as the way
 * out: "Undo #39 (page.restore) first."
 */
final readonly class UndoConflict
{
    /**
     * @param array<string, list<int|string>> $rows row ids by table
     */
    public function __construct(
        public string $message,
        public array $rows = [],
    ) {}
}
