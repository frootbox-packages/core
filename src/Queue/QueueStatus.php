<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use DateTimeImmutable;

/**
 * Whether the queue is worked off (decision 0221).
 */
final readonly class QueueStatus
{
    public function __construct(
        /** Jobs that could run now */
        public int $due,
        public int $failed,
        /** When schedule:run or queue:run last ran, null if never */
        public ?DateTimeImmutable $cronLastRun,
        /** Jobs are due and cron did not run for 10 minutes */
        public bool $cronMissing,
        /** The line for the crontab */
        public string $cronLine,
    ) {}
}
