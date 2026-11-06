<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * One run of an action: who, what, when, through which channel and
 * token on which device, and how it ended.
 */
final readonly class LogEntry extends Entity
{
    /**
     * @param array<string, mixed>|null $input Secrets are masked
     */
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $action,
        public string $channel,
        public string $actorType,
        public ?int $actorId,
        public ?string $actorName,
        public ?array $input,
        public EntryStatus $status,
        public ?string $error,
        public bool $undoable,
        public ?int $undoneBy,
        public int $durationMs,
        public DateTimeImmutable $createdAt,
        public ?int $tokenId = null,
        public ?string $device = null,
    ) {}
}
