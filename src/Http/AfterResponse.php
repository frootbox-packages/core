<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Http;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Work that runs after the response was sent, e.g. jobs of the queue
 * without cron (decision 0221). With PHP-FPM the visitor does not wait
 * for it; elsewhere the connection stays open until it is done.
 */
final class AfterResponse
{
    /** @var list<callable(): mixed> */
    private array $tasks = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param callable(): mixed $task
     */
    public function add(callable $task): void
    {
        $this->tasks[] = $task;
    }

    public function pending(): bool
    {
        return [] !== $this->tasks;
    }

    /**
     * Runs the tasks once. Errors are logged.
     */
    public function run(): void
    {
        $tasks = $this->tasks;
        $this->tasks = [];

        foreach ($tasks as $task) {
            try {
                $task();
            } catch (Throwable $error) {
                $this->logger->error('Work after the response failed.', ['exception' => $error]);
            }
        }
    }
}
