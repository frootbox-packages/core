<?php

/*
 * SPDX-License-Identifier: MIT
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
        /** URL of its own preview, null if it has none (decision 0148) */
        public ?string $preview = null,
    ) {}

    public static function of(Variant $variant, ?string $preview = null): self
    {
        return new self($variant->name, $variant->title, $variant->description, array_values(array_map(OptionSummary::of(...), $variant->options)), $preview);
    }

    /**
     * The variants of a block or layout with their previews.
     *
     * @return list<self>
     */
    public static function all(Component $component, Previews $previews = new Previews()): array
    {
        return array_values(array_map(static fn(Variant $variant): self => self::of($variant, $previews->url($component, $variant->name)), $component->variants));
    }
}
