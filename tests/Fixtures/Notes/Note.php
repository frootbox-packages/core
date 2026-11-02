<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use DateTimeImmutable;
use Frootbox\Database\Entity;

final readonly class Note extends Entity
{
    /**
     * @param list<string>|null $tags
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $body,
        public int $priority,
        public NoteStatus $status,
        public bool $pinned,
        public ?array $tags,
        public ?DateTimeImmutable $publishedAt,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
