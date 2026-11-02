<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Tools\Console\Command\GenerateCommand;
use Frootbox\Core\Database\MigrationsFactory;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Tests\Fixtures\Migrations\Version20261030000000;
use Frootbox\Core\Tests\Fixtures\Migrations\Version20261030000001;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Frootbox\Core\Tests\Support\TestDatabase;
use Frootbox\Database\Migration;
use Frootbox\Database\Schema\Blueprint;
use Frootbox\Database\Schema\ColumnDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Migration::class)]
#[CoversClass(Blueprint::class)]
#[CoversClass(ColumnDefinition::class)]
#[UsesClass(MigrationsFactory::class)]
#[UsesClass(MigrationSources::class)]
final class MigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(new DsnParser()->parse(TestDatabase::url()));
        TestDatabase::dropTables($this->connection, Version20261030000000::TABLE, 'renamed_items');
    }

    protected function tearDown(): void
    {
        TestDatabase::dropTables($this->connection, Version20261030000000::TABLE, 'renamed_items');
        $this->connection->close();
    }

    public function testDownRevertsTheChanges(): void
    {
        $this->execute(new Version20261030000000($this->connection, new NullLogger()), 'up');
        $this->execute(new Version20261030000001($this->connection, new NullLogger()), 'up');
        $this->execute(new Version20261030000001($this->connection, new NullLogger()), 'down');

        self::assertSame(['content', 'created_at', 'id', 'title', 'updated_at', 'visible'], $this->columns(Version20261030000000::TABLE));

        $this->execute(new Version20261030000000($this->connection, new NullLogger()), 'down');

        self::assertFalse($this->connection->createSchemaManager()->tablesExist([Version20261030000000::TABLE]));
    }

    public function testRenamesTables(): void
    {
        $this->execute(new Version20261030000000($this->connection, new NullLogger()), 'up');

        $migration = new class ($this->connection, new NullLogger()) extends Migration {
            public function up(Schema $schema): void
            {
                $this->renameTable(Version20261030000000::TABLE, 'renamed_items');
            }
        };
        $this->execute($migration, 'up');

        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['renamed_items']));
    }

    /**
     * SQLite locally; the CI runs this against MySQL and MariaDB.
     */
    public function testUsesTransactionsOnlyOutsideMysql(): void
    {
        $migration = new Version20261030000000($this->connection, new NullLogger());
        $isMysql = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;

        self::assertSame(!$isMysql, $migration->isTransactional());
    }

    public function testGeneratedMigrationsUseTheHelpers(): void
    {
        $project = TemporaryProject::create();
        $directory = $project->root . '/migrations';
        mkdir($directory);

        $factory = MigrationsFactory::create($this->connection, new MigrationSources()->add('Example\Migrations', $directory));
        $tester = new CommandTester(new GenerateCommand($factory));
        $tester->execute([]);

        $files = glob($directory . '/Version*.php');
        self::assertIsArray($files);
        self::assertCount(1, $files);

        $code = (string) file_get_contents($files[0]);
        $project->remove();

        self::assertStringContainsString('namespace Example\Migrations;', $code);
        self::assertStringContainsString('extends Migration', $code);
        self::assertStringContainsString('$this->createTable(', $code);
    }

    private function execute(Migration $migration, string $direction): void
    {
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    /**
     * @param non-empty-string $table
     *
     * @return list<string>
     */
    private function columns(string $table): array
    {
        $columns = array_map(
            static fn(Column $column): string => $column->getObjectName()->getIdentifier()->getValue(),
            $this->connection->createSchemaManager()->introspectTableColumnsByUnquotedName($table),
        );
        sort($columns);

        return $columns;
    }
}
