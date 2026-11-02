<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Field\Field;

/**
 * A block or layout, with its variants and assets resolved through the
 * theme chain.
 */
final readonly class Component
{
    /**
     * @param array<string, Field>   $fields
     * @param array<string, Area>    $areas
     * @param array<string, Variant> $variants Default first
     * @param array<string, string>  $assets   Path within assets/ => absolute path
     * @param list<string>           $keywords Further words for the search in the picker
     * @param array<string, string>  $previews Variant => absolute path of its preview, "Default" for preview.svg (decision 0148)
     */
    public function __construct(
        public ComponentKind $kind,
        public string $name,
        public string $path,
        public string $title,
        public string $description,
        public array $fields,
        public array $areas,
        public array $variants,
        public array $assets = [],
        /** Goes only into areas that allow it by name (decision 0124) */
        public bool $widget = false,
        /** The group in the block picker, null for "Other" (decision 0148) */
        public ?string $group = null,
        public array $keywords = [],
        public array $previews = [],
    ) {}

    public function package(): string
    {
        return ComponentName::package($this->name);
    }

    /**
     * The variant by name. Unknown names fall back to Default, so content
     * survives a theme without that variant.
     */
    public function variant(?string $name): Variant
    {
        return $this->variants[$name ?? Variant::DEFAULT] ?? $this->variants[Variant::DEFAULT] ?? array_values($this->variants)[0];
    }
}
