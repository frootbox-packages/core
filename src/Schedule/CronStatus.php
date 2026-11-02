<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Schedule;

use DateTimeImmutable;
use Frootbox\Core\ProjectPaths;

/**
 * When the cron job last ran, from a marker file that schedule:run and
 * queue:run touch (decision 0221). Without cron, jobs wait for visitors.
 */
final readonly class CronStatus
{
    /** Without a run for this long, cron counts as missing. */
    public const int MISSING_AFTER = 600;

    /** The line for the crontab, with the project folder. */
    public const string LINE = '* * * * * php %s/bin/frootbox schedule:run';

    public function __construct(private ProjectPaths $paths) {}

    public function touch(): void
    {
        $marker = $this->marker();
        $directory = \dirname($marker);

        if (is_dir($directory) || @mkdir($directory, 0o775, true) || is_dir($directory)) {
            @touch($marker);
        }
    }

    public function lastRun(): ?DateTimeImmutable
    {
        $time = is_file($this->marker()) ? filemtime($this->marker()) : false;

        return false === $time ? null : new DateTimeImmutable()->setTimestamp($time);
    }

    /**
     * Whether cron ran within the given seconds.
     */
    public function ranWithin(int $seconds): bool
    {
        $last = $this->lastRun();

        return null !== $last && $last->getTimestamp() > time() - $seconds;
    }

    public function line(): string
    {
        return \sprintf(self::LINE, $this->paths->root);
    }

    private function marker(): string
    {
        return $this->paths->var('cache/cron');
    }
}
