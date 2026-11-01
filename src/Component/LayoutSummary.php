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
     * @param list<FieldSummary>   $fields For the bounds of its lists on the page (decision 0145)
     */
    public function __construct(
        /** e.g. "acme/website/Page" */
        public string $name,
        public string $title,
        public string $description,
        public array $variants,
        public array $areas = [],
        public array $fields = [],
        /** URL of the preview for choosing a layout (decision 0148) */
        public ?string $preview = null,
    ) {}

    public static function of(Component $layout, Previews $previews = new Previews()): self
    {
        return new self(
            $layout->name,
            $layout->title,
            $layout->description,
            VariantSummary::all($layout, $previews),
            AreaSummary::all($layout->areas),
            FieldSummary::all($layout->fields),
            $previews->url($layout),
        );
    }
}
