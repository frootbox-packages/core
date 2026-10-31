<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
