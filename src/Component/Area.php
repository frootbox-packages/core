<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description = '',
        public ?array $allow = null,
        public ?int $min = null,
        public ?int $max = null,
    ) {}

    public function allows(string $block): bool
    {
        return null === $this->allow || \in_array($block, $this->allow, true);
    }
}
