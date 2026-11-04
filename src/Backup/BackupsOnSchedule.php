<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Frootbox\Core\Schedule\ScheduleRan;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * After each run of the scheduler: the daily dump a slow database owes
 * since its first change of the day, then thinning out (decision 0183).
 */
final readonly class BackupsOnSchedule
{
    public function __construct(
        private BackupManager $backups,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ScheduleRan $event): void
    {
        try {
            if ($this->backups->owesDaily()) {
                $this->backups->oweDaily(false);

                if ($this->backups->due($event->now)) {
                    $this->backups->create(BackupReason::Daily, $event->now);
                }
            }

            $this->backups->prune($event->now);
        } catch (Throwable $error) {
            $this->logger->error('Backups on schedule failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
        }
    }
}
