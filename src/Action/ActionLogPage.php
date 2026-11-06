<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * Entries of the log of actions, newest first, and where the older ones
 * start (pass as before), if there are more.
 */
final readonly class ActionLogPage
{
    /**
     * @param list<LogEntry> $entries
     */
    public function __construct(
        public array $entries,
        public ?int $next,
    ) {}
}
