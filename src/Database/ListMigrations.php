<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Backup\BackupManager;

/**
 * The migrations of the core and the extensions that have not run yet,
 * e.g. after a module came with Composer (decision 0277).
 *
 * @implements Query<MigrationList>
 */
#[AsQuery('system.migration-list', 'Lists the migrations of the core and the extensions that have not run yet, with their package, and whether a backup can be made before')]
final readonly class ListMigrations implements Query
{
    public function handle(Migrator $migrator, BackupManager $backups): MigrationList
    {
        return new MigrationList($migrator->pendingList(), $backups->available());
    }
}
