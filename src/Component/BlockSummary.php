<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * One block in the result of block.list (decision 0123).
 */
final readonly class BlockSummary
{
    /**
     * @param list<VariantSummary> $variants
     * @param list<FieldSummary>   $fields
     * @param list<AreaSummary>    $areas
     * @param list<string>         $keywords
     */
    public function __construct(
        /** e.g. "acme/website/Teaser" */
        public string $name,
        public string $title,
        public string $description,
        /** Goes only into areas that allow it by name (decision 0124) */
        public bool $widget,
        public array $variants,
        public array $fields,
        public array $areas,
        /** In the block picker, null for "Other" (decision 0148) */
        public ?string $group = null,
        public array $keywords = [],
        /** URL of the preview, an SVG in the colours of the theme or a picture; null without one */
        public ?string $preview = null,
        /** False if the themes hide it: it stays on pages, but cannot be added (decision 0162) */
        public bool $offered = true,
    ) {}

    public static function of(Component $block, Previews $previews = new Previews(), bool $offered = true): self
    {
        return new self(
            $block->name,
            $block->title,
            $block->description,
            $block->widget,
            VariantSummary::all($block, $previews),
            FieldSummary::all($block->fields),
            AreaSummary::all($block->areas),
            $block->group,
            $block->keywords,
            $previews->url($block),
            $offered,
        );
    }
}
