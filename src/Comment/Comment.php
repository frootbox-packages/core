<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A comment on a page, a block or a field (decision 0156). The first one
 * opens a thread and carries the place and the status, replies point to
 * it with $threadId.
 */
final readonly class Comment extends Entity
{
    public function __construct(
        public string $id,
        public ?string $threadId,
        public ?string $pageId,
        public ?string $language,
        public ?string $path,
        public ?string $blockId,
        public ?string $field,
        public bool $site,
        public ?string $excerpt,
        public ?int $viewport,
        public string $body,
        public string $authorName,
        public ?int $userId,
        public ?string $previewLinkId,
        public CommentStatus $status,
        public ?DateTimeImmutable $resolvedAt,
        public ?int $resolvedBy,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    public function isThread(): bool
    {
        return null === $this->threadId;
    }
}
