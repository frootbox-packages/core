<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * @implements Action<Backup>
 */
#[AsAction('system.backup-create', 'Makes a backup of the database by hand, which stays until someone deletes it', undoable: false)]
final readonly class CreateBackup implements Action
{
    public function handle(BackupManager $backups): Backup
    {
        return $backups->create(BackupReason::Manual);
    }
}
