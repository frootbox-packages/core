<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Schedule;

/**
 * What a run of the scheduler did.
 */
final readonly class ScheduleResult
{
    /**
     * @param list<string> $published   Ids of the pages published
     * @param list<string> $unpublished Ids of the pages taken offline
     * @param list<string> $failed      What could not be done, as messages
     */
    public function __construct(
        public array $published = [],
        public array $unpublished = [],
        public array $failed = [],
    ) {}
}
