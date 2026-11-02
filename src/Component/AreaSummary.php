<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * An area of a layout or block in layout.list and block.list: which
 * blocks it takes (decision 0123).
 */
final readonly class AreaSummary
{
    /**
     * @param list<string>|null $allow Full block names, null for all blocks except widgets
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public ?array $allow,
        public ?int $min,
        public ?int $max,
    ) {}

    public static function of(Area $area): self
    {
        return new self($area->name, $area->label, $area->description, $area->allow, $area->min, $area->max);
    }

    /**
     * @param array<string, Area> $areas
     *
     * @return list<self>
     */
    public static function all(array $areas): array
    {
        return array_values(array_map(self::of(...), $areas));
    }
}
