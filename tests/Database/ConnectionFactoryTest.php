<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Database;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Database\ConnectionFactory;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionFactory::class)]
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
