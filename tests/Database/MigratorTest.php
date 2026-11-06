<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Database\ConnectionFactory;
use Frootbox\Core\Database\MigrationJournal;
use Frootbox\Core\Database\MigrationsFactory;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\ProjectPaths;
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

/**
 * Runs against the database from DATABASE_URL: SQLite locally, MySQL and MariaDB in the CI.
 */
#[CoversClass(Migrator::class)]
#[CoversClass(MigrationsFactory::class)]
#[CoversClass(MigrationSources::class)]
#[CoversClass(Migration::class)]
#[CoversClass(Blueprint::class)]
#[CoversClass(ColumnDefinition::class)]
#[UsesClass(ConnectionFactory::class)]
#[UsesClass(MigrationJournal::class)]
#[UsesClass(Config::class)]
#[UsesClass(ProjectPaths::class)]
final class MigratorTest extends TestCase
{
    private TemporaryProject $project;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->connection = ConnectionFactory::create(
            new Config(Environment::Production, ['database' => ['url' => TestDatabase::url()]]),
            new ProjectPaths($this->project->root),
        );
        TestDatabase::dropTables($this->connection, Version20261030000000::TABLE, MigrationsFactory::TABLE);
    }

    protected function tearDown(): void
    {
        TestDatabase::dropTables($this->connection, Version20261030000000::TABLE, MigrationsFactory::TABLE);
        $this->connection->close();
        $this->project->remove();
    }

    public function testRunsPendingMigrationsOnce(): void
    {
        $migrator = $this->migrator();

        self::assertSame(2, $migrator->pending());
        self::assertSame([Version20261030000000::class, Version20261030000001::class], $migrator->migrate());

        self::assertSame(0, $migrator->pending());
        self::assertSame([], $migrator->migrate());
    }

    public function testCreatesAndAltersTheTable(): void
    {
        $this->migrator()->migrate();

        $schemaManager = $this->connection->createSchemaManager();
        $columns = array_map(
            static fn(Column $column): string => $column->getObjectName()->getIdentifier()->getValue(),
            $schemaManager->introspectTableColumnsByUnquotedName(Version20261030000000::TABLE),
        );
        sort($columns);

        self::assertSame(['body', 'created_at', 'id', 'position', 'slug', 'title', 'updated_at'], $columns);

        $indexes = array_map(
            static fn(Index $index): string => $index->getObjectName()->getIdentifier()->getValue(),
            $schemaManager->introspectTableIndexesByUnquotedName(Version20261030000000::TABLE),
        );

        self::assertContains('test_items_slug_unique', $indexes);
        self::assertContains('test_items_position_index', $indexes);
    }

    public function testCreatedTableStoresRows(): void
    {
        $this->migrator()->migrate();

        $this->connection->insert(Version20261030000000::TABLE, ['title' => 'Hallo', 'body' => '{"blocks":[]}', 'slug' => 'hallo']);
        $this->connection->insert(Version20261030000000::TABLE, ['title' => 'Welt']);

        $rows = $this->connection->fetchAllAssociative('SELECT id, title, position FROM ' . Version20261030000000::TABLE . ' ORDER BY id');

        self::assertCount(2, $rows);
        self::assertSame('Hallo', $rows[0]['title']);
        self::assertEquals(0, $rows[1]['position']);
        self::assertGreaterThan($rows[0]['id'], $rows[1]['id']);
    }

    public function testSlugIsUnique(): void
    {
        $this->migrator()->migrate();
        $this->connection->insert(Version20261030000000::TABLE, ['title' => 'A', 'slug' => 'same']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->insert(Version20261030000000::TABLE, ['title' => 'B', 'slug' => 'same']);
    }

    public function testWorksWithoutAnyMigrations(): void
    {
        $empty = $this->project->root . '/migrations';
        mkdir($empty);

        $migrator = new Migrator($this->connection, new MigrationSources()->add('Empty\Migrations', $empty), $this->journal());

        self::assertSame(0, $migrator->pending());
        self::assertSame([], $migrator->migrate());
    }

    private function migrator(): Migrator
    {
        $sources = new MigrationSources()->add('Frootbox\Core\Tests\Fixtures\Migrations', \dirname(__DIR__) . '/Fixtures/Migrations');

        return new Migrator($this->connection, $sources, $this->journal());
    }

    private function journal(): MigrationJournal
    {
        $journal = new Journal();

        return new MigrationJournal($this->connection, new ActionLog($this->connection, $journal), $journal, new CurrentContext());
    }
}
