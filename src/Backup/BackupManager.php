<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use DateTimeImmutable;
use Frootbox\Core\Config\Config;
use Frootbox\Core\ProjectPaths;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes, lists and thins out the backups of the database in
 * storage/backups/ (decision 0183). The work is done by the Driver of the
 * database.
 *
 * Settings: backup.interval_hours (24, 0 turns the daily backup off),
 * backup.stages, backup.max_months (12), backup.keep_migrate (3).
 */
final readonly class BackupManager
{
    private const string NAME = '/^(\d{4}-\d{2}-\d{2}-\d{6})-(daily|migrate|manual|restore)(?:-\d+)?\.(sqlite\.gz|sql\.gz)$/';

    /** Marks that a slow driver owes the daily backup to the scheduler. */
    private const string PENDING = '.daily-pending';

    public function __construct(
        private Driver $driver,
        private ProjectPaths $paths,
        private Config $config,
        private LoggerInterface $logger,
    ) {}

    public function available(): bool
    {
        return $this->driver->available();
    }

    public function canRestore(): bool
    {
        return $this->driver->canRestore();
    }

    public function create(BackupReason $reason, ?DateTimeImmutable $now = null): Backup
    {
        if (!$this->driver->available()) {
            throw new BackupException('There is no database file to back up.');
        }

        $now ??= new DateTimeImmutable();
        $folder = $this->folder();

        if (!is_dir($folder) && !mkdir($folder, 0o775, true) && !is_dir($folder)) {
            throw new BackupException('Could not create storage/backups/.');
        }

        $base = $now->format('Y-m-d-His') . '-' . $reason->value;
        $name = $base . '.' . $this->driver->extension();

        for ($count = 2; is_file($folder . '/' . $name); ++$count) {
            $name = $base . '-' . $count . '.' . $this->driver->extension();
        }

        $file = $folder . '/' . $name;
        $part = $file . '.part';

        try {
            $this->driver->create($part);
            rename($part, $file);
        } catch (Throwable $error) {
            if (is_file($part)) {
                unlink($part);
            }

            throw $error instanceof BackupException ? $error : new BackupException('The backup failed: ' . $error->getMessage(), previous: $error);
        }

        $backup = $this->get($name) ?? throw new BackupException('The backup was not written.');
        $this->logger->info('Backup {name} created ({size} bytes)', ['name' => $name, 'size' => $backup->size]);

        return $backup;
    }

    /**
     * @return list<Backup> newest first
     */
    public function all(): array
    {
        $files = glob($this->folder() . '/*.gz');
        $backups = [];

        foreach (\is_array($files) ? $files : [] as $file) {
            $backup = $this->get(basename($file));

            if (null !== $backup) {
                $backups[] = $backup;
            }
        }

        usort($backups, static fn(Backup $a, Backup $b): int => [$b->createdAt, $b->name] <=> [$a->createdAt, $a->name]);

        return $backups;
    }

    public function get(string $name): ?Backup
    {
        if (1 !== preg_match(self::NAME, $name, $match) || !is_file($this->folder() . '/' . $name)) {
            return null;
        }

        $createdAt = DateTimeImmutable::createFromFormat('!Y-m-d-His', $match[1]);
        $size = filesize($this->folder() . '/' . $name);

        return false === $createdAt ? null : new Backup($name, BackupReason::from($match[2]), $createdAt, false === $size ? 0 : $size);
    }

    /**
     * The file of a backup, for downloading or restoring.
     */
    public function file(Backup $backup): string
    {
        return $this->folder() . '/' . $backup->name;
    }

    public function delete(string $name): bool
    {
        $backup = $this->get($name);

        if (null === $backup) {
            return false;
        }

        unlink($this->file($backup));
        $this->logger->info('Backup {name} deleted', ['name' => $name]);

        return true;
    }

    public function latest(): ?Backup
    {
        return $this->all()[0] ?? null;
    }

    /**
     * Whether the last backup is older than backup.interval_hours.
     */
    public function due(?DateTimeImmutable $now = null): bool
    {
        $hours = $this->config->int('backup.interval_hours', 24);

        if ($hours <= 0) {
            return false;
        }

        $latest = $this->latest();
        $now ??= new DateTimeImmutable();

        return null === $latest || $latest->createdAt <= $now->modify(\sprintf('-%d hours', $hours));
    }

    /**
     * Deletes backups the retention no longer keeps.
     *
     * @return list<string> the names deleted
     */
    public function prune(?DateTimeImmutable $now = null): array
    {
        $deleted = [];

        foreach ($this->retention()->expired($this->all(), $now ?? new DateTimeImmutable()) as $backup) {
            if ($this->delete($backup->name)) {
                $deleted[] = $backup->name;
            }
        }

        return $deleted;
    }

    public function owesDaily(): bool
    {
        return is_file($this->folder() . '/' . self::PENDING);
    }

    public function oweDaily(bool $owes): void
    {
        $marker = $this->folder() . '/' . self::PENDING;

        if (!$owes) {
            if (is_file($marker)) {
                unlink($marker);
            }

            return;
        }

        if (!is_dir($this->folder())) {
            mkdir($this->folder(), 0o775, true);
        }

        touch($marker);
    }

    public function retention(): Retention
    {
        $configured = $this->config->get('backup.stages', Retention::STAGES);
        $stages = [];

        foreach (\is_array($configured) ? $configured : Retention::STAGES as $period => $until) {
            if (\is_string($period) && \is_string($until)) {
                $stages[$period] = $until;
            }
        }

        return new Retention(
            $stages,
            $this->config->int('backup.max_months', 12),
            $this->config->int('backup.keep_migrate', 3),
        );
    }

    private function folder(): string
    {
        return $this->paths->storage('backups');
    }
}
