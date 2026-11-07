<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Visitor;

use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Schedule\ScheduleRan;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes expired visitor sessions once an hour after a run of the
 * scheduler (decision 0312).
 */
final readonly class PruneVisitorSessions
{
    private const int EVERY = 3600;

    public function __construct(
        private VisitorSessions $sessions,
        private ProjectPaths $paths,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ScheduleRan $event): void
    {
        $marker = $this->paths->var('schedule/visitor-sessions-pruned');
        $now = $event->now;

        if (is_file($marker) && $now->getTimestamp() - (int) filemtime($marker) < self::EVERY) {
            return;
        }

        try {
            $this->sessions->prune($now);
        } catch (Throwable $error) {
            $this->logger->warning('Pruning the visitor sessions failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
        }

        if (!is_dir(\dirname($marker))) {
            @mkdir(\dirname($marker), 0o775, true);
        }

        @touch($marker, $now->getTimestamp());
    }
}
