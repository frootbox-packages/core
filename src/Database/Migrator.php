<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Frootbox\Database\Migration;
use Throwable;

/**
 * Brings the database up to date, e.g. after an installation or an update.
 * Every migration leaves an entry in the log of actions (decision 0174).
 */
final readonly class Migrator
{
    public function __construct(
        private Connection $connection,
        private MigrationSources $sources,
        private MigrationJournal $journal,
    ) {}

    /**
     * The migrations that have not run yet, in the order they run.
     *
     * @return list<PendingMigration>
     */
    public function pendingList(): array
    {
        $factory = $this->factory();
        $pending = [];

        foreach ($this->plan($factory)->getItems() as $item) {
            $version = (string) $item->getVersion();
            $pending[] = new PendingMigration($version, $this->sources->packageOf($version), $item->getMigration()->getDescription());
        }

        return $pending;
    }

    /**
     * Runs all migrations that have not run yet.
     *
     * @return list<string> the versions that ran
     */
    public function migrate(): array
    {
        $factory = $this->factory();
        $versions = [];

        // One at a time, so each gets its own entry with what it changed.
        foreach ($this->plan($factory)->getItems() as $item) {
            $version = (string) $item->getVersion();
            $migration = new PendingMigration($version, $this->sources->packageOf($version), $item->getMigration()->getDescription());
            $changes = [];
            $started = hrtime(true);

            Migration::recordWith(static function (string $table, int|string $id, array $before, array $after) use (&$changes): void {
                $changes[] = ['table' => $table, 'id' => $id, 'before' => $before, 'after' => $after];
            });

            try {
                $factory->getMigrator()->migrate(new MigrationPlanList([$item], Direction::UP), new MigratorConfiguration());
            } catch (Throwable $error) {
                $this->journal->failed($migration, $error, self::since($started));

                throw $error;
            } finally {
                Migration::recordWith(null);
            }

            $this->journal->migrated($migration, $changes, self::since($started));
            $versions[] = $version;
        }

        return $versions;
    }

    /**
     * Counts migrations that have not run yet.
     */
    public function pending(): int
    {
        return \count($this->factory()->getMigrationStatusCalculator()->getNewMigrations());
    }

    /**
     * A fresh one each time: Doctrine freezes the migrations it ran, so a
     * second run in the same process, e.g. after an installation, needs new ones.
     */
    private function factory(): DependencyFactory
    {
        $factory = MigrationsFactory::create($this->connection, $this->sources);
        $factory->getMetadataStorage()->ensureInitialized();

        return $factory;
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function plan(DependencyFactory $factory): MigrationPlanList
    {
        return $factory->getMigrationPlanCalculator()->getPlanForVersions(
            array_map(
                static fn(AvailableMigration $migration): Version => $migration->getVersion(),
                $factory->getMigrationStatusCalculator()->getNewMigrations()->getItems(),
            ),
            'up',
        );
    }
}
