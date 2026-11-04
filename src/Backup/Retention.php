<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use DateTimeImmutable;

/**
 * Thins out backups by age like Time Machine (decision 0183): all of the
 * last days, then one per calendar week, month and quarter, nothing older
 * than the highest age. In each period the oldest one stays, so the kept
 * state does not move with every new backup.
 *
 * Backups made by hand always stay, as do the last few before migrations.
 */
final readonly class Retention
{
    public const array STAGES = ['all' => '7 days', 'week' => '5 weeks', 'month' => '6 months'];

    /**
     * @param array<string, string> $stages      period (all, week, month) => up to which age
     * @param int                   $maxMonths   older backups go, the rest after the stages is kept per quarter
     * @param int                   $keepMigrate the last backups before migrations that always stay
     */
    public function __construct(
        private array $stages = self::STAGES,
        private int $maxMonths = 12,
        private int $keepMigrate = 3,
    ) {}

    /**
     * @param list<Backup> $backups
     *
     * @return list<Backup> those to delete
     */
    public function expired(array $backups, DateTimeImmutable $now): array
    {
        $oldest = $now->modify(\sprintf('-%d months', $this->maxMonths));
        $protected = $this->protected($backups);
        $buckets = [];
        $delete = [];

        // Oldest first, so the first one in a period is the one that stays.
        usort($backups, static fn(Backup $a, Backup $b): int => $a->createdAt <=> $b->createdAt);

        foreach ($backups as $backup) {
            if (isset($protected[$backup->name])) {
                continue;
            }

            if ($backup->createdAt < $oldest) {
                $delete[] = $backup;

                continue;
            }

            $period = $this->period($backup->createdAt, $now);

            if ('all' === $period) {
                continue;
            }

            $key = $period . ':' . self::bucket($period, $backup->createdAt);

            if (isset($buckets[$key])) {
                $delete[] = $backup;
            } else {
                $buckets[$key] = true;
            }
        }

        return $delete;
    }

    /**
     * @param list<Backup> $backups
     *
     * @return array<string, true>
     */
    private function protected(array $backups): array
    {
        $protected = [];
        $migrate = array_filter($backups, static fn(Backup $backup): bool => BackupReason::Migrate === $backup->reason);
        usort($migrate, static fn(Backup $a, Backup $b): int => $b->createdAt <=> $a->createdAt);

        foreach (\array_slice($migrate, 0, max(0, $this->keepMigrate)) as $backup) {
            $protected[$backup->name] = true;
        }

        foreach ($backups as $backup) {
            if (BackupReason::Manual === $backup->reason) {
                $protected[$backup->name] = true;
            }
        }

        return $protected;
    }

    private function period(DateTimeImmutable $createdAt, DateTimeImmutable $now): string
    {
        foreach (['all', 'week', 'month'] as $period) {
            $until = $this->stages[$period] ?? null;

            if (null !== $until && $createdAt >= $now->modify('-' . $until)) {
                return $period;
            }
        }

        return 'quarter';
    }

    private static function bucket(string $period, DateTimeImmutable $createdAt): string
    {
        return match ($period) {
            'week' => $createdAt->format('o-W'),
            'month' => $createdAt->format('Y-m'),
            default => $createdAt->format('Y') . '-Q' . intdiv((int) $createdAt->format('n') - 1, 3),
        };
    }
}
