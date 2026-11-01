<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use DateTimeImmutable;

/**
 * A thread of comments with its place, status and replies (decision 0156).
 */
final readonly class CommentThread
{
    /**
     * @param list<CommentEntry> $replies oldest first
     */
    public function __construct(
        public string $id,
        public CommentStatus $status,
        public ?string $pageId,
        /** The title of the page, null if it was deleted */
        public ?string $pageTitle,
        public ?string $language,
        /** The address where it was written, e.g. "/kontakt" */
        public ?string $path,
        /** The block it is about, null for the page */
        public ?string $blockId,
        /** The field inside the block or, with $site, of the website, e.g. "slides/0/title" */
        public ?string $field,
        public bool $site,
        /** The text at the place when it was written */
        public ?string $excerpt,
        /** The width of the window in pixels */
        public ?int $viewport,
        public CommentEntry $comment,
        public array $replies,
        public ?DateTimeImmutable $resolvedAt,
        /** The name of the user who marked it done */
        public ?string $resolvedBy,
    ) {}
}
