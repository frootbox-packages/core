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
 * without cron (decision 0221). With PHP-FPM or LiteSpeed the visitor
 * does not wait for it; elsewhere, e.g. with mod_fcgid, the connection
 * stays open until it is done, and Apache may give up with a 500
 * (decision 0325).
 */
final class AfterResponse
{
    /** @var list<callable(): mixed> */
    private array $tasks = [];

    private ?bool $detached = null;

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param callable(): mixed $task
     */
    public function add(callable $task): void
    {
        $this->tasks[] = $task;
    }

    /**
     * Whether the response reaches the visitor before the work.
     */
    public function detached(): bool
    {
        return $this->detached ??= \function_exists('fastcgi_finish_request') || \function_exists('litespeed_finish_request');
    }

    /**
     * For tests: as if the server could or could not send the response first.
     *
     * @internal
     */
    public function assumeDetached(bool $detached): void
    {
        $this->detached = $detached;
    }

    /**
     * Ends the response, so the visitor does not wait for the work, where
     * the server can.
     *
     * @internal
     */
    public static function finishResponse(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (\function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
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
