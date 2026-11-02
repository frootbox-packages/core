<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;

/**
 * Brings the database up to date, e.g. after an installation or an update.
 */
final readonly class Migrator
{
    public function __construct(private DependencyFactory $dependencyFactory) {}

    /**
     * Runs all migrations that have not run yet.
     *
     * @return list<string> the versions that ran
     */
    public function migrate(): array
    {
        $this->dependencyFactory->getMetadataStorage()->ensureInitialized();

        $plan = $this->dependencyFactory->getMigrationPlanCalculator()->getPlanForVersions(
            array_map(
                static fn(AvailableMigration $migration): Version => $migration->getVersion(),
                $this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations()->getItems(),
            ),
            'up',
        );

        if (0 === \count($plan)) {
            return [];
        }

        $this->dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());

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
        $this->dependencyFactory->getMetadataStorage()->ensureInitialized();

        return \count($this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations());
    }
}
