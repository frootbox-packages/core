<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A comment on a page, a block or a field (decision 0156), or on an
 * element of the design page (decision 0210). The first one
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
        /** The element of the design page instead of a page (decision 0210) */
        public ?string $design,
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
