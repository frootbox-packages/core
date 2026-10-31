<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
    ) {}

    public static function of(Component $block): self
    {
        return new self(
            $block->name,
            $block->title,
            $block->description,
            $block->widget,
            VariantSummary::all($block->variants),
            FieldSummary::all($block->fields),
            AreaSummary::all($block->areas),
        );
    }
}
