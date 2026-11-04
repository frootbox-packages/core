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
use Doctrine\Migrations\Version\Version;

/**
 * Brings the database up to date, e.g. after an installation or an update.
 */
final readonly class Migrator
{
    public function __construct(
        private Connection $connection,
        private MigrationSources $sources,
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
        $plan = $this->plan($factory);

        if (0 === \count($plan)) {
            return [];
        }

        $factory->getMigrator()->migrate($plan, new MigratorConfiguration());

        $versions = [];

        foreach ($plan->getItems() as $item) {
            $versions[] = (string) $item->getVersion();
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
