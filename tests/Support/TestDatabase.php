<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * The database the tests run against.
 *
 * DATABASE_URL defaults to in-memory SQLite (phpunit.xml.dist). The CI sets
 * it to MySQL and MariaDB.
 */
final class TestDatabase
{
    public static function url(): string
    {
        $url = getenv('DATABASE_URL');

        return \is_string($url) && '' !== $url ? $url : 'pdo-sqlite:///:memory:';
    }

    /**
     * Drops tables so the next test starts empty, also on MySQL and MariaDB.
     */
    public static function dropTables(Connection $connection, string ...$tables): void
    {
        $schemaManager = $connection->createSchemaManager();

        foreach ($tables as $table) {
            if ($schemaManager->tablesExist([$table])) {
                $connection->executeStatement($connection->getDatabasePlatform()->getDropTableSQL($table));
            }
        }
    }
}
