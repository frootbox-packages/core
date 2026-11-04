<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Database;

use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Backup\BackupManager;
use Frootbox\Core\Backup\BackupReason;
use Frootbox\Core\Database\ListMigrations;
use Frootbox\Core\Database\MigrationList;
use Frootbox\Core\Database\MigrationRun;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\Database\PendingMigration;
use Frootbox\Core\Database\RunMigrations;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Tests\Fixtures\Migrations\Version20261030000000;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pending migrations from the backend or the API (decision 0277), here
 * with a database file so a backup can be made.
 */
#[CoversClass(ListMigrations::class)]
#[CoversClass(RunMigrations::class)]
#[CoversClass(MigrationList::class)]
#[CoversClass(MigrationRun::class)]
#[CoversClass(PendingMigration::class)]
#[CoversClass(MigrationSources::class)]
#[CoversClass(Migrator::class)]
final class MigrateActionTest extends NotesTestCase
{
    private const string FIRST = 'Frootbox\Core\Tests\Fixtures\Migrations\Version20261030000000';

    private const string SECOND = 'Frootbox\Core\Tests\Fixtures\Migrations\Version20261030000001';

    protected function setUp(): void
    {
        parent::setUp();
        // As if the package with these migrations just came with Composer.
        $this->connection()->executeStatement('DROP TABLE ' . Version20261030000000::TABLE);
        $this->connection()->delete('migrations', ['version' => self::FIRST]);
        $this->connection()->delete('migrations', ['version' => self::SECOND]);
    }

    public function testListsWhatIsPendingWithItsPackage(): void
    {
        $list = $this->runner()->ask(new ListMigrations());

        self::assertEquals([
            new PendingMigration(self::FIRST, 'acme/fixtures', 'Creates the test table test_items'),
            new PendingMigration(self::SECOND, 'acme/fixtures', 'Adds slug and position to test_items'),
        ], $list->pending);
        self::assertTrue($list->backup);
        self::assertSame('frootbox/core', $this->get(MigrationSources::class)->packageOf('Frootbox\Core\Migrations\Version20261030000000'));
    }

    public function testRunsThemAfterABackup(): void
    {
        $run = $this->runner()->run(new RunMigrations());

        self::assertSame([self::FIRST, self::SECOND], $run->versions);
        self::assertNotNull($run->backup);
        self::assertSame(BackupReason::Migrate, $this->get(BackupManager::class)->get($run->backup)?->reason);
        self::assertSame([], $this->runner()->ask(new ListMigrations())->pending);
        self::assertSame([], $this->connection()->fetchAllAssociative('SELECT * FROM ' . Version20261030000000::TABLE), 'The table is there again.');
        self::assertEquals(new MigrationRun([], null), $this->runner()->run(new RunMigrations()), 'Nothing left, no backup.');
    }

    public function testRunsWithoutATransactionAndOnlyForSuperusers(): void
    {
        $descriptor = $this->get(Catalog::class)->get('system.migrate');

        self::assertFalse($descriptor->transactional);
        self::assertFalse($descriptor->undoable);
        self::assertTrue($this->get(Catalog::class)->get('page.create')->transactional);
    }

    protected function register(Registry $registry): void
    {
        $registry->migrations('Frootbox\Core\Tests\Fixtures\Migrations', \dirname(__DIR__) . '/Fixtures/Migrations', 'acme/fixtures');
    }

    protected function tables(): array
    {
        return [Version20261030000000::TABLE];
    }

    protected function config(): array
    {
        return ['database' => ['url' => 'pdo-sqlite:///storage/database.sqlite']];
    }
}
