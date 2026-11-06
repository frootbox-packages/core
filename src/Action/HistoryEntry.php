<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * An entry of the log with its changes of the rows asked for.
 */
final readonly class HistoryEntry
{
    /**
     * @param list<Change> $changes in the order they happened
     */
    public function __construct(
        public LogEntry $entry,
        public array $changes,
    ) {}
}
