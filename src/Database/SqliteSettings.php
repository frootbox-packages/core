<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Pdo\Sqlite;
use SensitiveParameter;

/**
 * Makes SQLite fit for a live website with several visitors at once.
 *
 * - WAL: readers never wait for a writer and a writer never waits for readers.
 * - synchronous=NORMAL: safe with WAL, a power cut may lose only the last
 *   transaction, never the file.
 * - busy_timeout: a second writer waits instead of failing at once.
 * - BEGIN IMMEDIATE (pdo_sqlite only): a transaction takes the write lock
 *   when it starts. A deferred one that reads first and writes later fails
 *   with "database is locked" without waiting when another writer was faster.
 */
final class SqliteSettings implements Middleware
{
    public const int BUSY_TIMEOUT_MS = 10_000;

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[SensitiveParameter]
                array $params,
            ): Connection {
                if ('pdo_sqlite' === ($params['driver'] ?? null)) {
                    $params['driverOptions'] = [Sqlite::ATTR_TRANSACTION_MODE => Sqlite::TRANSACTION_MODE_IMMEDIATE] + ($params['driverOptions'] ?? []);
                }

                $connection = parent::connect($params);

                // The timeout first: switching to WAL needs a lock.
                $connection->exec('PRAGMA busy_timeout = ' . SqliteSettings::BUSY_TIMEOUT_MS);
                $connection->exec('PRAGMA journal_mode = WAL');
                $connection->exec('PRAGMA synchronous = NORMAL');

                return $connection;
            }
        };
    }
}
