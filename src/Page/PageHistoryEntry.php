<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;

/**
 * Who changed a page, when, through which channel and on which device,
 * with which action and how it ended (decision 0174).
 */
final readonly class PageHistoryEntry
{
    /**
     * @param array<string, mixed> $input   of the action, secrets masked
     * @param list<PageChange>     $changes
     */
    public function __construct(
        public int $id,
        public string $action,
        public string $status,
        public string $actorType,
        public ?int $actorId,
        public ?string $actorName,
        public string $channel,
        public ?string $device,
        public DateTimeImmutable $createdAt,
        public bool $undoable,
        public bool $undone,
        public array $input,
        public array $changes,
    ) {}
}
