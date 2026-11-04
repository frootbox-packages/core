<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Doctrine\Migrations\DependencyFactory;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\ProjectPaths;
use Psr\Log\LoggerInterface;

/**
 * Puts a backup of SQLite back (decision 0183): first a backup of the
 * current state, so the restore itself can be taken back. While the file
 * is replaced, the website answers 503 (RestoreLock). Afterwards the
 * migrations the backup lacks run and the page cache is emptied.
 */
final readonly class Restorer
{
    public function __construct(
        private BackupManager $backups,
        private Driver $driver,
        private Migrator $migrator,
        private DependencyFactory $migrations,
        private PageCache $pageCache,
        private ProjectPaths $paths,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{before: Backup, migrated: list<string>}
     */
    public function restore(string $name): array
    {
        $backup = $this->backups->get($name) ?? throw new BackupException(\sprintf('There is no backup %s.', $name));

        if (!$this->driver->canRestore()) {
            throw new BackupException('Frootbox restores only SQLite. Restore a dump of MySQL or MariaDB with the tools of your host.');
        }

        $unknown = array_diff($this->driver->migrations($this->backups->file($backup)), $this->known());

        if ([] !== $unknown) {
            throw new BackupException(\sprintf('The backup %s has migrations this code does not know (%s). Update the code first.', $name, implode(', ', $unknown)));
        }

        $lock = $this->paths->storage(RestoreLock::FILE);
        touch($lock);

        try {
            $before = $this->backups->create(BackupReason::Restore);
            $this->driver->restore($this->backups->file($backup));
            $migrated = $this->migrator->migrate();
            $this->pageCache->clear();
        } finally {
            unlink($lock);
        }

        $this->logger->notice('Backup {name} restored, the state before is {before}', ['name' => $name, 'before' => $before->name]);

        return ['before' => $before, 'migrated' => $migrated];
    }

    /**
     * @return list<string>
     */
    private function known(): array
    {
        $versions = [];

        foreach ($this->migrations->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $versions[] = (string) $migration->getVersion();
        }

        return $versions;
    }
}
