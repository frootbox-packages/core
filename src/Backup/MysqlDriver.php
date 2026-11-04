<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PDO;
use Pdo\Mysql;
use PDOStatement;

/**
 * Dumps MySQL and MariaDB as .sql.gz in PHP, so it runs on webspace
 * without exec() (decision 0183). The dump reads a consistent snapshot
 * without locking and streams the rows unbuffered straight into the file.
 *
 * Frootbox does not restore a dump; it goes in with the tools of the
 * host such as phpMyAdmin or mysql.
 */
final readonly class MysqlDriver implements Driver
{
    /** Bytes of one INSERT statement before the next begins. */
    private const int STATEMENT_SIZE = 1 << 20;

    public function __construct(private Connection $connection) {}

    public function available(): bool
    {
        return true;
    }

    public function extension(): string
    {
        return 'sql.gz';
    }

    public function slow(): bool
    {
        return true;
    }

    public function create(string $file): void
    {
        // A connection of its own: the snapshot must not mix with a transaction of the website.
        $dump = DriverManager::getConnection($this->connection->getParams());
        $pdo = $dump->getNativeConnection();

        if (!$pdo instanceof PDO) {
            throw new BackupException('Backups of MySQL and MariaDB need pdo_mysql.');
        }

        $out = gzopen($file, 'wb6');

        if (false === $out) {
            throw new BackupException(\sprintf('Could not write %s.', basename($file)));
        }

        try {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);

            self::write($out, \sprintf("-- Backup of %s, %s\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n\n", $dump->getDatabase() ?? 'the database', date('c')));

            foreach (self::tables($pdo) as $table) {
                $quoted = '`' . str_replace('`', '``', $table) . '`';
                $create = self::column($pdo, 'SHOW CREATE TABLE ' . $quoted, 1);
                self::write($out, "DROP TABLE IF EXISTS {$quoted};\n{$create};\n\n");
                self::rows($pdo, $out, $quoted);
            }

            self::write($out, "SET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n");
            $pdo->exec('COMMIT');
        } finally {
            gzclose($out);
            $dump->close();
        }
    }

    public function canRestore(): bool
    {
        return false;
    }

    public function migrations(string $file): array
    {
        return [];
    }

    public function restore(string $file): void
    {
        throw new BackupException('Restore a dump of MySQL or MariaDB with the tools of your host, e.g. phpMyAdmin or mysql.');
    }

    /**
     * @return list<string>
     */
    private static function tables(PDO $pdo): array
    {
        $statement = self::query($pdo, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            if (\is_array($row) && \is_string($row[0] ?? null)) {
                $tables[] = $row[0];
            }
        }

        $statement->closeCursor();

        return $tables;
    }

    private static function column(PDO $pdo, string $sql, int $column): string
    {
        $statement = self::query($pdo, $sql);
        $row = $statement->fetch(PDO::FETCH_NUM);
        $statement->closeCursor();
        $value = \is_array($row) ? ($row[$column] ?? null) : null;

        return \is_string($value) ? $value : throw new BackupException(\sprintf('No answer to %s.', $sql));
    }

    /**
     * @param resource $out
     */
    private static function rows(PDO $pdo, $out, string $table): void
    {
        $statement = self::query($pdo, 'SELECT * FROM ' . $table);
        $prefix = "INSERT INTO {$table} VALUES\n";
        $buffer = '';

        while (false !== ($row = $statement->fetch(PDO::FETCH_NUM))) {
            $values = array_map(static fn(mixed $value): string => match (true) {
                null === $value => 'NULL',
                \is_int($value), \is_float($value) => (string) $value,
                \is_bool($value) => $value ? '1' : '0',
                default => $pdo->quote(\is_string($value) ? $value : ''),
            }, $row);

            $buffer .= ('' === $buffer ? $prefix : ",\n") . '(' . implode(', ', $values) . ')';

            if (\strlen($buffer) >= self::STATEMENT_SIZE) {
                self::write($out, $buffer . ";\n");
                $buffer = '';
            }
        }

        $statement->closeCursor();

        if ('' !== $buffer) {
            self::write($out, $buffer . ";\n");
        }

        self::write($out, "\n");
    }

    private static function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);

        return false === $statement ? throw new BackupException(\sprintf('Could not run %s.', $sql)) : $statement;
    }

    /**
     * @param resource $out
     */
    private static function write($out, string $text): void
    {
        if (false === gzwrite($out, $text)) {
            throw new BackupException('Could not write the backup.');
        }
    }
}
