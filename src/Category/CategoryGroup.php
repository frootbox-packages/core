<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

/**
 * A group of categories, e.g. "Rubrik" of the news or "Abteilung" for
 * pages, people, news and jobs at once (decision 0233). An extension
 * registers its groups:
 *
 *     $registry->add(CategoryGroup::class, new CategoryGroup('acme/shop/categories', 'Categories', ['acme/shop/product']));
 *
 * A group of an extension is named after its package, one from
 * config/categories.yaml has a name without a slash, one an admin
 * creates its id. Presets are categories the group brings along; they
 * are created when the presets are applied (decision 0239).
 */
final readonly class CategoryGroup
{
    /**
     * @param list<string>         $types   The types of objects it applies to, e.g. "frootbox/news/article"
     * @param list<CategoryPreset> $presets
     */
    public function __construct(
        public string $key,
        /** English for an extension, translated in the backend; as written for the others */
        public string $title,
        public array $types,
        public GroupSource $source = GroupSource::Module,
        public array $presets = [],
    ) {}

    public function appliesTo(string $type): bool
    {
        return \in_array($type, $this->types, true);
    }
}
