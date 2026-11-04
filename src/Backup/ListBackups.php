<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<BackupList>
 */
#[AsQuery('system.backup-list', 'Lists the backups of the database, newest first, and whether they can be restored here')]
final readonly class ListBackups implements Query
{
    public function handle(BackupManager $backups): BackupList
    {
        return new BackupList($backups->all(), $backups->available(), $backups->canRestore());
    }
}
