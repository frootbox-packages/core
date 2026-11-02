<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Database;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Database\ConnectionFactory;
use Frootbox\Core\Database\SqliteSettings;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionFactory::class)]
#[CoversClass(SqliteSettings::class)]
#[UsesClass(Config::class)]
#[UsesClass(ProjectPaths::class)]
final class ConnectionFactoryTest extends TestCase
{
    private ?TemporaryProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->remove();
    }

    public function testResolvesSqlitePathAgainstProjectRoot(): void
    {
        $connection = ConnectionFactory::create($this->config('pdo-sqlite:///storage/database.sqlite'), $this->paths());

        self::assertNotNull($this->project);
        self::assertSame($this->paths()->root . '/storage/database.sqlite', $connection->getParams()['path'] ?? null);

        $connection->executeQuery('SELECT 1');
        self::assertFileExists($this->paths()->storage('database.sqlite'));
    }

    public function testPreparesSqliteForSeveralVisitorsAtOnce(): void
    {
        $connection = ConnectionFactory::create($this->config('pdo-sqlite:///storage/database.sqlite'), $this->paths());

        self::assertSame('wal', $connection->fetchOne('PRAGMA journal_mode'));
        self::assertSame(1, $connection->fetchOne('PRAGMA synchronous'));
        self::assertSame(SqliteSettings::BUSY_TIMEOUT_MS, $connection->fetchOne('PRAGMA busy_timeout'));

        $pdo = $connection->getNativeConnection();
        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame(Sqlite::TRANSACTION_MODE_IMMEDIATE, $pdo->getAttribute(Sqlite::ATTR_TRANSACTION_MODE));
    }

    public function testSecondWriterWaitsInsteadOfFailing(): void
    {
        $first = ConnectionFactory::create($this->config('pdo-sqlite:///storage/database.sqlite'), $this->paths());
        $second = ConnectionFactory::create($this->config('pdo-sqlite:///storage/database.sqlite'), $this->paths());
        $first->executeStatement('CREATE TABLE notes (id INTEGER)');

        // The second reads, then wants to write, while the first holds the
        // write lock. Deferred, it would fail at once; immediate, it waits.
        $first->beginTransaction();
        $first->executeStatement('INSERT INTO notes VALUES (1)');
        $second->executeStatement('PRAGMA busy_timeout = 50');

        try {
            $second->beginTransaction();
            self::fail('The second transaction should wait for the lock.');
        } catch (LockWaitTimeoutException) {
        }

        $first->commit();
        $second->beginTransaction();
        self::assertSame(1, $second->fetchOne('SELECT COUNT(*) FROM notes'));
        $second->executeStatement('INSERT INTO notes VALUES (2)');
        $second->commit();
    }

    public function testKeepsAbsoluteSqlitePath(): void
    {
        $connection = ConnectionFactory::create($this->config('pdo-sqlite:////tmp/frootbox.sqlite'), $this->paths());

        self::assertSame('/tmp/frootbox.sqlite', $connection->getParams()['path'] ?? null);
    }

    public function testParsesMysqlUrlWithoutConnecting(): void
    {
        $connection = ConnectionFactory::create($this->config('pdo-mysql://user:secret@db.example:3307/site'), $this->paths());
        $params = $connection->getParams();

        self::assertSame('pdo_mysql', $params['driver'] ?? null);
        self::assertSame('db.example', $params['host'] ?? null);
        self::assertSame(3307, $params['port'] ?? null);
        self::assertSame('site', $params['dbname'] ?? null);
        self::assertFalse($connection->isConnected());
    }

    public function testFailsWithoutDatabaseUrl(): void
    {
        $this->expectException(ConfigException::class);

        ConnectionFactory::create(new Config(Environment::Production), $this->paths());
    }

    private function config(string $url): Config
    {
        return new Config(Environment::Production, ['database' => ['url' => $url]]);
    }

    private function paths(): ProjectPaths
    {
        $this->project ??= TemporaryProject::create();

        return new ProjectPaths($this->project->root);
    }
}
