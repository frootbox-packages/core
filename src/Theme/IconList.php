<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * One page of symbols and all sets, the result of icon.list.
 */
final readonly class IconList
{
    /**
     * @param list<IconSummary>    $items
     * @param list<IconSetSummary> $sets
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public array $sets,
    ) {}
}
