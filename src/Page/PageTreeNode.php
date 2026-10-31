<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

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
    ) {}
}
