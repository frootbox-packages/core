<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Backup;

use Frootbox\Core\Backup\BackupException;
use Frootbox\Core\Backup\BackupManager;
use Frootbox\Core\Backup\BackupReason;
use Frootbox\Core\Backup\CreateBackup;
use Frootbox\Core\Backup\DailyBackup;
use Frootbox\Core\Backup\DeleteBackup;
use Frootbox\Core\Backup\Gzip;
use Frootbox\Core\Backup\ListBackups;
use Frootbox\Core\Backup\RestoreLock;
use Frootbox\Core\Backup\Restorer;
use Frootbox\Core\Backup\SqliteDriver;
use Frootbox\Core\Console\MigrateCommand;
use Frootbox\Core\Database\MigrationsFactory;
use Frootbox\Core\Http\CallableRequestHandler;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Fixtures\Notes\Note;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Database\EntityNotFoundException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Backups of a real SQLite file in the temporary project, also when the
 * other tests run against MySQL or MariaDB.
 */
#[CoversClass(BackupManager::class)]
#[CoversClass(SqliteDriver::class)]
#[CoversClass(DailyBackup::class)]
#[CoversClass(Restorer::class)]
#[CoversClass(RestoreLock::class)]
#[CoversClass(Gzip::class)]
final class BackupTest extends NotesTestCase
{
    public function testTheFirstChangeOfTheDayMakesABackupBefore(): void
    {
        $backups = $this->get(BackupManager::class);
        self::assertNull($backups->latest());

        $this->runner()->run(new CreateNote('First'));
        $this->runner()->run(new CreateNote('Second'));

        $all = $backups->all();
        self::assertCount(1, $all);
        self::assertSame(BackupReason::Daily, $all[0]->reason);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-\d{6}-daily\.sqlite\.gz$/', $all[0]->name);
        self::assertSame([], $this->notesIn($all[0]->name), 'The state before the change.');
        self::assertFalse($backups->due());
    }

    public function testBackupsByHand(): void
    {
        $this->runner()->run(new CreateNote('Kept'));
        $manual = $this->runner()->run(new CreateBackup());

        self::assertSame(BackupReason::Manual, $manual->reason);
        self::assertSame(['Kept'], $this->notesIn($manual->name));

        $list = $this->runner()->ask(new ListBackups());
        self::assertTrue($list->available);
        self::assertTrue($list->canRestore);
        self::assertSame([$manual->name], array_map(static fn($backup): string => $backup->name, array_filter($list->backups, static fn($backup): bool => BackupReason::Manual === $backup->reason)));

        $this->runner()->run(new DeleteBackup($manual->name));
        self::assertNull($this->get(BackupManager::class)->get($manual->name));

        $this->expectException(EntityNotFoundException::class);
        $this->runner()->run(new DeleteBackup('../database.sqlite'));
    }

    public function testRestoresAfterABackupOfTheCurrentState(): void
    {
        $this->runner()->run(new CreateNote('Kept'));
        $manual = $this->runner()->run(new CreateBackup());
        $this->runner()->run(new CreateNote('Lost'));

        $result = $this->get(Restorer::class)->restore($manual->name);

        self::assertSame(['Kept'], array_map(static fn(Note $note): string => $note->title, iterator_to_array($this->notes()->fetch(), false)));
        self::assertSame(BackupReason::Restore, $result['before']->reason);
        self::assertSame(['Kept', 'Lost'], $this->notesIn($result['before']->name), 'The restore can be taken back.');
        self::assertSame([], $result['migrated']);
        self::assertFileDoesNotExist($this->get(ProjectPaths::class)->root . '/storage/' . RestoreLock::FILE);
    }

    public function testRefusesBackupsNewerThanTheCode(): void
    {
        $this->runner()->run(new CreateNote('Kept'));
        $this->connection()->executeStatement('INSERT INTO ' . MigrationsFactory::TABLE . " (version, executed_at, execution_time) VALUES ('Acme\\Future\\Version29990101000000', NULL, 0)");
        $manual = $this->runner()->run(new CreateBackup());

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Acme\Future\Version29990101000000');
        $this->get(Restorer::class)->restore($manual->name);
    }

    public function testTheWebsiteWaitsWhileRestoring(): void
    {
        touch($this->get(ProjectPaths::class)->root . '/storage/' . RestoreLock::FILE);
        $lock = new RestoreLock(new ProjectPaths($this->get(ProjectPaths::class)->root), new Psr17Factory());
        $response = $lock->process(new ServerRequest('GET', '/'), new CallableRequestHandler(static fn() => throw new \LogicException('Not reached.')));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
    }

    public function testMigrateMakesNoBackupWhenNothingIsPending(): void
    {
        $tester = new CommandTester($this->get(MigrateCommand::class));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->get(BackupManager::class)->all());
    }

    protected function config(): array
    {
        return ['database' => ['url' => 'pdo-sqlite:///storage/database.sqlite']];
    }

    /**
     * @return list<string>
     */
    private function notesIn(string $name): array
    {
        $file = $this->get(ProjectPaths::class)->root . '/storage/backups/' . $name;
        $copy = $file . '.test.sqlite';
        Gzip::unpack($file, $copy);
        $pdo = new \PDO('sqlite:' . $copy);
        $statement = $pdo->query('SELECT title FROM notes ORDER BY id');
        $titles = false === $statement ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        unset($statement, $pdo);
        unlink($copy);

        return array_values(array_filter($titles, is_string(...)));
    }
}
