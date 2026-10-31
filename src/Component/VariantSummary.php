<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * A variant of a layout or block, e.g. "Default" or "Wide".
 */
final readonly class VariantSummary
{
    /**
     * @param list<OptionSummary> $options
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array $options = [],
    ) {}

    public static function of(Variant $variant): self
    {
        return new self($variant->name, $variant->title, $variant->description, array_values(array_map(OptionSummary::of(...), $variant->options)));
    }

    /**
     * @param array<string, Variant> $variants
     *
     * @return list<self>
     */
    public static function all(array $variants): array
    {
        return array_values(array_map(self::of(...), $variants));
    }
}
