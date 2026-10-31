<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * One layout in the result of layout.list.
 */
final readonly class LayoutSummary
{
    /**
     * @param list<VariantSummary> $variants
     * @param list<AreaSummary>    $areas
     */
    public function __construct(
        /** e.g. "acme/website/Page" */
        public string $name,
        public string $title,
        public string $description,
        public array $variants,
        public array $areas = [],
    ) {}

    public static function of(Component $layout): self
    {
        return new self(
            $layout->name,
            $layout->title,
            $layout->description,
            VariantSummary::all($layout->variants),
            AreaSummary::all($layout->areas),
        );
    }
}
