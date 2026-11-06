<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use DateTimeImmutable;
use Frootbox\Core\Config\Config;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Schedule\ScheduleRan;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shortens old changes and deletes old log entries once an hour after a
 * run of the scheduler, like actions:prune, so a website without a
 * console keeps its log in bounds too (decision 0174).
 */
final readonly class PruneOnSchedule
{
    private const int EVERY = 3600;

    public function __construct(
        private ActionLog $log,
        private Config $config,
        private ProjectPaths $paths,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ScheduleRan $event): void
    {
        $marker = $this->paths->var('schedule/actions-pruned');
        $now = $event->now;

        if (is_file($marker) && $now->getTimestamp() - (int) filemtime($marker) < self::EVERY) {
            return;
        }

        try {
            self::prune($this->log, $this->config, $now);
        } catch (Throwable $error) {
            $this->logger->warning('Pruning the action log failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
        }

        if (!is_dir(\dirname($marker))) {
            @mkdir(\dirname($marker), 0o775, true);
        }

        @touch($marker, $now->getTimestamp());
    }

    /**
     * With actions.undo_days (default 30) and actions.log_days (default 365).
     *
     * @return array{shortened: int, entries: int, undoDays: int, logDays: int}
     */
    public static function prune(ActionLog $log, Config $config, DateTimeImmutable $now): array
    {
        $undoDays = $config->int('actions.undo_days', 30);
        $logDays = $config->int('actions.log_days', 365);

        return $log->prune($now->modify(\sprintf('-%d days', $undoDays)), $now->modify(\sprintf('-%d days', $logDays))) + ['undoDays' => $undoDays, 'logDays' => $logDays];
    }
}
