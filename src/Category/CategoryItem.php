<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

/**
 * A category in the order of its tree, with its depth (decision 0233).
 */
final readonly class CategoryItem
{
    /**
     * @param array<mixed> $translations {"de": {"title": "Kurse", "slug": "kurse", "description": "…"}}
     */
    public function __construct(
        public string $id,
        public string $group,
        public ?string $parentId,
        /** 0 at the top */
        public int $depth,
        /** Place among its siblings, 0 for the first */
        public int $position,
        public array $translations,
    ) {}

    public function title(string $language): ?string
    {
        return $this->text($language, 'title');
    }

    public function slug(string $language): ?string
    {
        return $this->text($language, 'slug');
    }

    public static function of(Category $category, int $depth): self
    {
        return new self($category->id, $category->groupKey, $category->parentId, $depth, $category->position, $category->translations);
    }

    private function text(string $language, string $key): ?string
    {
        $translation = $this->translations[$language] ?? null;
        $value = \is_array($translation) ? $translation[$key] ?? null : null;

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
