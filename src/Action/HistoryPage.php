<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * Entries of a history, newest first, and where the next older ones
 * start (pass as before), if there are more.
 */
final readonly class HistoryPage
{
    /**
     * @param list<HistoryEntry> $entries
     */
    public function __construct(
        public array $entries,
        public ?int $next,
    ) {}
}
