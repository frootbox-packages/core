<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

/**
 * The queue with its status.
 */
final readonly class JobList
{
    /**
     * @param list<JobSummary> $jobs waiting ones by due date, then failed ones
     */
    public function __construct(
        public QueueStatus $status,
        public array $jobs,
    ) {}
}
