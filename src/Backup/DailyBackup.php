<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The backup before the first change of the day (decision 0183). The
 * Runner asks before every action that is not part of another one. The
 * check is a look into storage/backups/.
 *
 * SQLite is copied at once, before the action. A dump of MySQL takes
 * longer, so the action only leaves a note and the scheduler makes the
 * dump after the response (BackupsOnSchedule). A failed backup is logged
 * and never stops the action.
 */
final readonly class DailyBackup
{
    public function __construct(
        private BackupManager $backups,
        private Driver $driver,
        private LoggerInterface $logger,
    ) {}

    public function beforeAction(string $action): void
    {
        // Their own backups need none of the day before them.
        if (str_starts_with($action, 'system.backup-')) {
            return;
        }

        try {
            if (!$this->driver->available() || !$this->backups->due()) {
                return;
            }

            if ($this->driver->slow()) {
                if (!$this->backups->owesDaily()) {
                    $this->backups->oweDaily(true);
                }

                return;
            }

            $this->backups->create(BackupReason::Daily);
        } catch (Throwable $error) {
            $this->logger->error('The daily backup failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
        }
    }
}
