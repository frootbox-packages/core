<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Doctrine\DBAL\Connection;

/**
 * Chooses the backup driver for the database of the website.
 */
final class Drivers
{
    public static function create(Connection $connection): Driver
    {
        return match ($connection->getParams()['driver'] ?? null) {
            'pdo_mysql', 'mysqli' => new MysqlDriver($connection),
            default => new SqliteDriver($connection),
        };
    }
}
