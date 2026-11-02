<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use DateTimeImmutable;

/**
 * One comment of a thread as the queries return it (decision 0156).
 */
final readonly class CommentEntry
{
    public function __construct(
        public string $id,
        public string $body,
        public string $authorName,
        /** Written by a guest with a preview link rather than a user */
        public bool $guest,
        public ?DateTimeImmutable $createdAt,
    ) {}
}
