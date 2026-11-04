<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Database\MigrationsFactory;
use PDO;
use Throwable;

/**
 * Backs up SQLite with VACUUM INTO, which is safe while the website runs
 * and blocks no writer in WAL mode, then packs the copy (decision 0183).
 *
 * The copy runs on a connection of its own, so it also works while the
 * connection of the website is inside a transaction.
 */
final readonly class SqliteDriver implements Driver
{
    public function __construct(private Connection $connection) {}

    public function available(): bool
    {
        $path = $this->path();

        return null !== $path && is_file($path);
    }

    public function extension(): string
    {
        return 'sqlite.gz';
    }

    public function slow(): bool
    {
        return false;
    }

    public function create(string $file): void
    {
        $path = $this->path() ?? throw new BackupException('There is no SQLite file to back up.');
        $copy = $file . '.sqlite';

        try {
            $pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $pdo->exec('VACUUM INTO ' . $pdo->quote($copy));
            unset($pdo);
            Gzip::pack($copy, $file);
        } finally {
            if (is_file($copy)) {
                unlink($copy);
            }
        }
    }

    public function canRestore(): bool
    {
        return true;
    }

    public function migrations(string $file): array
    {
        $copy = $file . '.check.sqlite';

        try {
            Gzip::unpack($file, $copy);
            $pdo = new PDO('sqlite:' . $copy, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->query('SELECT version FROM ' . MigrationsFactory::TABLE);
            $versions = false === $statement ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
            unset($statement, $pdo);

            return array_values(array_map(strval(...), array_filter($versions, is_scalar(...))));
        } catch (Throwable $error) {
            throw new BackupException(\sprintf('%s is no readable backup: %s', basename($file), $error->getMessage()), previous: $error);
        } finally {
            if (is_file($copy)) {
                unlink($copy);
            }
        }
    }

    public function restore(string $file): void
    {
        $path = $this->path() ?? throw new BackupException('There is no SQLite file to restore into.');
        $copy = $path . '.restore';
        Gzip::unpack($file, $copy);

        // Everything from the WAL into the file, then let go of it.
        $this->connection->executeStatement('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->connection->close();

        if (!rename($copy, $path)) {
            unlink($copy);

            throw new BackupException('Could not replace the database file.');
        }

        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    }

    private function path(): ?string
    {
        $params = $this->connection->getParams();

        if (true === ($params['memory'] ?? false)) {
            return null;
        }

        $path = $params['path'] ?? null;

        return \is_string($path) && '' !== $path && ':memory:' !== $path ? $path : null;
    }
}
