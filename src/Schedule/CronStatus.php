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
        return self::time($this->marker());
    }

    /**
     * Notes a run the hub asked for (decision 0293). Apart from cron: the
     * hub asks only websites without one.
     */
    public function ticked(): void
    {
        $marker = $this->paths->var('cache/hub-tick');
        $directory = \dirname($marker);

        if (is_dir($directory) || @mkdir($directory, 0o775, true) || is_dir($directory)) {
            @touch($marker);
        }
    }

    /**
     * When the hub last asked for a run.
     */
    public function lastTick(): ?DateTimeImmutable
    {
        return self::time($this->paths->var('cache/hub-tick'));
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

    private static function time(string $file): ?DateTimeImmutable
    {
        clearstatcache(true, $file);
        $time = is_file($file) ? filemtime($file) : false;

        return false === $time ? null : new DateTimeImmutable()->setTimestamp($time);
    }

    private function marker(): string
    {
        return $this->paths->var('cache/cron');
    }
}
