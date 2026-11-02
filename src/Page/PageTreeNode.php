<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;

/**
 * One page in the result of page.tree.
 */
final readonly class PageTreeNode
{
    /**
     * @param array<string, string> $titles   by language
     * @param array<string, string> $slugs    by language
     * @param list<self>            $children
     */
    public function __construct(
        public string $id,
        public PageStatus $status,
        public string $layout,
        public array $titles,
        public array $slugs,
        public array $children,
        /** When the draft is published by itself, then the status in the backend is "scheduled" */
        public ?DateTimeImmutable $publishAt = null,
        /** The page's own lock, see page.lock */
        public Reach $locked = Reach::None,
        /** What applies, maybe from a page above (decision 0117) */
        public PageVisibility $visibility = new PageVisibility(),
        /** Only shown as the way to a branch of the actor (decision 0118) */
        public bool $outside = false,
    ) {}
}
