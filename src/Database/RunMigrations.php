<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Backup\BackupException;
use Frootbox\Core\Backup\BackupManager;
use Frootbox\Core\Backup\BackupReason;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Support\Message;
use Psr\Log\LoggerInterface;

/**
 * Runs all pending migrations after a backup of the database, like
 * bin/frootbox migrate (decision 0277). Without a backup nothing runs,
 * unless withoutBackup is set. Changes to the schema end a transaction
 * on MySQL, so the action runs without one.
 *
 * @implements Action<MigrationRun>
 */
#[AsAction('system.migrate', 'Runs all pending migrations of the core and the extensions, after a backup of the database', undoable: false, transactional: false)]
final readonly class RunMigrations implements Action
{
    public function __construct(
        #[Description('Run them even if no backup can be made before')]
        public bool $withoutBackup = false,
    ) {}

    public function handle(Migrator $migrator, BackupManager $backups, PageCache $cache, LoggerInterface $logger): MigrationRun
    {
        if (0 === $migrator->pending()) {
            return new MigrationRun([], null);
        }

        $backup = null;

        if (!$this->withoutBackup) {
            try {
                $backup = $backups->create(BackupReason::Migrate)->name;
            } catch (BackupException $error) {
                throw new ConflictException(new Message('The backup failed, so nothing was migrated: {error}', ['error' => $error->getMessage()]));
            }
        }

        $versions = $migrator->migrate();
        $cache->clear();
        $logger->info('Ran {count} migration(s): {versions}', ['count' => \count($versions), 'versions' => implode(', ', $versions)]);

        return new MigrationRun($versions, $backup);
    }
}
