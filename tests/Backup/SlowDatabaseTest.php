<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Backup;

use DateTimeImmutable;
use Frootbox\Core\Backup\BackupManager;
use Frootbox\Core\Backup\BackupReason;
use Frootbox\Core\Backup\BackupsOnSchedule;
use Frootbox\Core\Backup\DailyBackup;
use Frootbox\Core\Backup\Driver;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Schedule\ScheduleRan;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A dump of MySQL takes long, so the daily one waits for the scheduler.
 */
#[CoversClass(DailyBackup::class)]
#[CoversClass(BackupsOnSchedule::class)]
final class SlowDatabaseTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTheSchedulerMakesTheDailyDump(): void
    {
        $driver = new class implements Driver {
            public int $dumps = 0;

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
                ++$this->dumps;
                file_put_contents($file, (string) gzencode('-- dump'));
            }

            public function canRestore(): bool
            {
                return false;
            }

            public function migrations(string $file): array
            {
                return [];
            }

            public function restore(string $file): void {}
        };
        $backups = new BackupManager($driver, new ProjectPaths($this->project->root), new Config(Environment::Development), new NullLogger());
        $daily = new DailyBackup($backups, $driver, new NullLogger());
        $schedule = new BackupsOnSchedule($backups, new NullLogger());

        $schedule(new ScheduleRan(new DateTimeImmutable()));
        self::assertSame(0, $driver->dumps, 'Nothing changed, nothing to dump.');

        $daily->beforeAction('note.create');
        $daily->beforeAction('note.create');
        self::assertSame(0, $driver->dumps, 'Not while the action waits.');
        self::assertTrue($backups->owesDaily());

        $schedule(new ScheduleRan(new DateTimeImmutable()));
        self::assertSame(1, $driver->dumps);
        self::assertFalse($backups->owesDaily());
        self::assertSame(BackupReason::Daily, $backups->latest()?->reason);

        $daily->beforeAction('note.create');
        $schedule(new ScheduleRan(new DateTimeImmutable()));
        self::assertSame(1, $driver->dumps, 'Once a day.');
    }
}
