<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Backup;

use DateTimeImmutable;
use Frootbox\Core\Backup\Backup;
use Frootbox\Core\Backup\BackupReason;
use Frootbox\Core\Backup\Retention;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Retention::class)]
final class RetentionTest extends TestCase
{
    public function testThinsOutLikeTimeMachine(): void
    {
        $now = new DateTimeImmutable('2026-11-04 12:00');
        $kept = self::keep(self::daily($now, 400), $now);
        $ages = array_map(static fn(Backup $backup): int => (int) floor(($now->getTimestamp() - $backup->createdAt->getTimestamp()) / 86400), $kept);

        self::assertCount(7, array_filter($ages, static fn(int $days): bool => $days < 7), 'All of the last week.');
        self::assertGreaterThan(330, max([0, ...$ages]), 'One almost a year old.');
        self::assertLessThanOrEqual(366, max([0, ...$ages]), 'Nothing older than 12 months.');
        self::assertGreaterThanOrEqual(15, \count($kept));
        self::assertLessThanOrEqual(20, \count($kept));
    }

    public function testTheKeptStateDoesNotMove(): void
    {
        $now = new DateTimeImmutable('2026-11-04 12:00');
        $kept = self::keep(self::daily($now, 400), $now);

        // Each day one more backup: at most one leaves at each border of a stage and one at the end.
        for ($day = 1; $day <= 60; ++$day) {
            $later = $now->modify(\sprintf('+%d days', $day));
            $before = array_map(static fn(Backup $backup): string => $backup->name, $kept);
            $kept = self::keep([...$kept, self::backup($later->setTime(3, 0))], $later);
            $after = array_map(static fn(Backup $backup): string => $backup->name, $kept);

            self::assertLessThanOrEqual(4, \count(array_diff($before, $after)), $later->format('Y-m-d'));
        }

        self::assertGreaterThanOrEqual(15, \count($kept));
        self::assertLessThanOrEqual(20, \count($kept));
    }

    public function testKeepsManualAndTheLastBeforeMigrations(): void
    {
        $now = new DateTimeImmutable('2026-11-04 12:00');
        $manual = self::backup($now->modify('-3 years'), BackupReason::Manual);
        $migrate = [];

        for ($hour = 1; $hour <= 5; ++$hour) {
            $migrate[] = self::backup($now->modify(\sprintf('-20 days -%d hours', $hour)), BackupReason::Migrate);
        }

        $expired = array_map(static fn(Backup $backup): string => $backup->name, new Retention()->expired([$manual, ...$migrate], $now));

        self::assertNotContains($manual->name, $expired);
        self::assertNotContains($migrate[0]->name, $expired);
        self::assertNotContains($migrate[1]->name, $expired);
        self::assertNotContains($migrate[2]->name, $expired);
        self::assertContains($migrate[3]->name, $expired, 'Thinned out like the others.');
    }

    /**
     * @return list<Backup>
     */
    private static function daily(DateTimeImmutable $now, int $days): array
    {
        $backups = [];

        for ($day = 0; $day < $days; ++$day) {
            $backups[] = self::backup($now->setTime(3, 0)->modify(\sprintf('-%d days', $day)));
        }

        return $backups;
    }

    /**
     * @param list<Backup> $backups
     *
     * @return list<Backup>
     */
    private static function keep(array $backups, DateTimeImmutable $now): array
    {
        $expired = array_map(static fn(Backup $backup): string => $backup->name, new Retention()->expired($backups, $now));

        return array_values(array_filter($backups, static fn(Backup $backup): bool => !\in_array($backup->name, $expired, true)));
    }

    private static function backup(DateTimeImmutable $createdAt, BackupReason $reason = BackupReason::Daily): Backup
    {
        return new Backup($createdAt->format('Y-m-d-His') . '-' . $reason->value . '.sqlite.gz', $reason, $createdAt, 100);
    }
}
