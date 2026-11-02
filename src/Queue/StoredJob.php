<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A job in the queue (decision 0221).
 */
final readonly class StoredJob extends Entity
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
        public string $actorType,
        public ?int $actorId,
        public ?string $actorName,
        public DateTimeImmutable $availableAt,
        public int $attempts,
        public int $maxAttempts,
        public ?string $lastError,
        public ?DateTimeImmutable $lockedUntil,
        public ?DateTimeImmutable $failedAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
