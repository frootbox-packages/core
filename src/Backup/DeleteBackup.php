<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Database\EntityNotFoundException;

/**
 * @implements Action<void>
 */
#[AsAction('system.backup-delete', 'Deletes a backup of the database for good', undoable: false)]
final readonly class DeleteBackup implements Action
{
    public function __construct(public string $name) {}

    public function handle(BackupManager $backups): void
    {
        if (!$backups->delete($this->name)) {
            throw EntityNotFoundException::for('storage/backups', $this->name);
        }
    }
}
