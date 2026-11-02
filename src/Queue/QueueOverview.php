<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use DateTimeImmutable;
use Frootbox\Core\Schedule\CronStatus;

/**
 * Reads the queue for its screen and the warning without cron.
 */
final readonly class QueueOverview
{
    public function __construct(
        private Jobs $jobs,
        private JobClasses $classes,
        private CronStatus $cron,
    ) {}

    public function status(): QueueStatus
    {
        $now = new DateTimeImmutable();
        $due = $this->jobs->countDue($now);

        return new QueueStatus(
            $due,
            $this->jobs->countFailed(),
            $this->cron->lastRun(),
            $due > 0 && !$this->cron->ranWithin(CronStatus::MISSING_AFTER),
            $this->cron->line(),
        );
    }

    public function list(): JobList
    {
        $now = new DateTimeImmutable();

        return new JobList($this->status(), array_map(fn(StoredJob $job): JobSummary => new JobSummary(
            $job->id,
            $job->name,
            null === ($class = $this->classes->get($job->name)) ? '' : JobClasses::attribute($class)->description,
            match (true) {
                null !== $job->failedAt => 'failed',
                null !== $job->lockedUntil && $job->lockedUntil > $now => 'running',
                default => 'waiting',
            },
            $job->actorName ?? $job->actorType,
            $job->availableAt,
            $job->attempts,
            $job->maxAttempts,
            $job->lastError,
            $job->failedAt,
            $job->createdAt,
        ), $this->jobs->all()));
    }
}
