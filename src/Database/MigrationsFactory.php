<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;

/**
 * Sets up Doctrine Migrations for all migration sources.
 */
final class MigrationsFactory
{
    public const string TABLE = 'migrations';

    private const string TEMPLATE = __DIR__ . '/../../resources/migration.tpl';

    public static function create(Connection $connection, MigrationSources $sources): DependencyFactory
    {
        $configuration = new Configuration();

        foreach ($sources->all() as $namespace => $directory) {
            $configuration->addMigrationsDirectory($namespace, $directory);
        }

        $storage = new TableMetadataStorageConfiguration();
        $storage->setTableName(self::TABLE);
        $configuration->setMetadataStorageConfiguration($storage);

        // New migrations extend Frootbox\Core\Database\Migration, which
        // also decides per database whether a transaction is used.
        $configuration->setCustomTemplate(self::TEMPLATE);

        return DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($connection),
        );
    }
}
