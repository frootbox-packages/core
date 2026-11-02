<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use DateTimeImmutable;

/**
 * A job in the queue, for the backend and the REST API.
 */
final readonly class JobSummary
{
    public function __construct(
        public string $id,
        public string $name,
        /** From #[AsJob], empty if no extension knows the job */
        public string $description,
        /** "waiting", "running" or "failed" */
        public string $state,
        /** Who put it in the queue; it runs with their rights */
        public string $actor,
        public DateTimeImmutable $availableAt,
        public int $attempts,
        public int $maxAttempts,
        public ?string $lastError,
        public ?DateTimeImmutable $failedAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
