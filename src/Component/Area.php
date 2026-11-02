<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * A named place for further blocks, e.g. the columns of a grid or the main
 * area of a layout.
 */
final readonly class Area
{
    /**
     * @param list<string>|null $allow Full block names, null for all blocks
     * @param int|null          $level The level its headings start at, e.g. 2 for a sidebar that never has the h1 (decision 0129)
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description = '',
        public ?array $allow = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?int $level = null,
    ) {}

    /**
     * Widgets only go where the area names them (decision 0124).
     */
    public function allows(string $block, bool $widget = false): bool
    {
        return null === $this->allow ? !$widget : \in_array($block, $this->allow, true);
    }
}
