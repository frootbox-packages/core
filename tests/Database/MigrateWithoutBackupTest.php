<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Database;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Database\ListMigrations;
use Frootbox\Core\Database\RunMigrations;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Tests\Fixtures\Migrations\Version20261030000000;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * system.migrate where no backup can be made, here a database in memory
 * (decision 0277), and the rules for actions without a transaction.
 */
#[CoversClass(RunMigrations::class)]
#[CoversClass(Descriptor::class)]
#[CoversClass(Runner::class)]
final class MigrateWithoutBackupTest extends NotesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->executeStatement('DROP TABLE ' . Version20261030000000::TABLE);
        foreach (['Version20261030000000', 'Version20261030000001'] as $version) {
            $this->connection()->delete('migrations', ['version' => 'Frootbox\Core\Tests\Fixtures\Migrations\\' . $version]);
        }
    }

    public function testRunsNothingWithoutABackupUnlessAsked(): void
    {
        self::assertFalse($this->runner()->ask(new ListMigrations())->backup);

        try {
            $this->runner()->run(new RunMigrations());
            self::fail('It migrated without a backup.');
        } catch (ConflictException $error) {
            self::assertStringStartsWith('The backup failed, so nothing was migrated: ', $error->getMessage());
        }

        self::assertCount(2, $this->runner()->ask(new ListMigrations())->pending);

        $run = $this->runner()->run(new RunMigrations(withoutBackup: true));
        self::assertCount(2, $run->versions);
        self::assertNull($run->backup);
    }

    public function testOnlySystemActionsThatCannotBeUndoneRunWithoutATransaction(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('may only run without a transaction as a system.* action with "undoable: false"');

        Descriptor::of(WithoutTransaction::class);
    }

    public function testDoesNotRunInsideAnotherAction(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('system.migrate runs without a transaction and so not inside another action.');

        $this->connection()->transactional(fn() => $this->runner()->run(new RunMigrations(withoutBackup: true)));
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
        return ['database' => ['url' => 'pdo-sqlite:///:memory:']];
    }
}

/**
 * @implements Action<null>
 */
#[AsAction('acme/shop/sync.run', 'Syncs without a transaction', undoable: false, transactional: false)]
final readonly class WithoutTransaction implements Action
{
    public function handle(): null
    {
        return null;
    }
}
