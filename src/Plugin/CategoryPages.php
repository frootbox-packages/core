<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Category\Categories;
use Frootbox\Core\Category\Category;
use Frootbox\Core\Category\ObjectCategories;
use Frootbox\Core\Component\BlockContext;
use Frootbox\Core\Url\Subpage;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;

/**
 * The pages of the categories below the page of a plugin (decision
 * 0243), e.g. /team/pflege/intensivpflege. With the switch on, every
 * category of the groups of the type has an address below the page; the
 * path is the chain of slugs from the top. The view of the plugin shows
 * the entries of the category there.
 */
final readonly class CategoryPages
{
    public function __construct(
        private ObjectCategories $objects,
        private Categories $categories,
        private Urls $urls,
    ) {}

    /**
     * The paths of the categories of a type below a page, for the
     * addresses. A category whose chain lacks a slug in a language has
     * no address there; of two with the same path the first one wins.
     *
     * @param list<string> $languages The languages the page has an address in
     *
     * @return array<string, array<string, string>> by target, e.g. "category:01J…-01K…" => ["de" => "pflege/intensivpflege"]
     */
    public function paths(string $type, string $pageId, array $languages): array
    {
        $tree = $this->objects->tree($type);
        $paths = [];

        foreach ($languages as $language) {
            $chains = [];
            $taken = [];

            // The tree lists each category before the ones below it.
            foreach ($tree as $item) {
                $slug = $item->slug($language);
                $parent = null === $item->parentId ? '' : $chains[$item->parentId] ?? null;

                if (null === $slug || null === $parent) {
                    continue;
                }

                $path = ('' === $parent ? '' : $parent . '/') . $slug;
                $chains[$item->id] = $path;

                if (!isset($taken[$path])) {
                    $taken[$path] = true;
                    $paths[(string) Target::category($pageId, $item->id)][$language] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * The page of a category for the delivery: title and description of
     * the category, the pages of the ones above it for the breadcrumb.
     */
    public function subpage(Target $target, string $path, string $language): ?Subpage
    {
        [$pageId, $category] = $this->find($target) ?? [null, null];

        if (null === $pageId || null === $category) {
            return null;
        }

        $parents = [];
        $above = $category;

        while (null !== $above->parentId && null !== $above = $this->categories->fetchById($above->parentId)) {
            array_unshift($parents, ['target' => Target::category($pageId, $above->id), 'title' => $above->titleIn([$language])]);
        }

        return new Subpage($target, $path, $category->titleIn([$language]), $category->description($language) ?? '', null, $parents);
    }

    /**
     * The title of the page of a category in a language, e.g. for the
     * statistics; null if there is no such category.
     */
    public function title(Target $target, string $language): ?string
    {
        $found = $this->find($target);

        return null === $found ? null : $found[1]->titleIn([$language]);
    }

    /**
     * The category of the page of a category, with the ids of all below
     * it, for the view of the plugin on that page; null elsewhere.
     *
     * @return array{Category, list<string>}|null
     */
    public function current(BlockContext $context): ?array
    {
        $target = $context->subpage?->target;
        [$pageId, $category] = null === $target ? [null, null] : $this->find($target) ?? [null, null];

        if (null === $category || null === $context->target || $pageId !== $context->target->id) {
            return null;
        }

        return [$category, $this->categories->withDescendants($category)];
    }

    /**
     * The address of the page of a category below the page the view of
     * the plugin is on; null without the switch or without an address,
     * then lists filter with ?category= instead.
     */
    public function href(BlockContext $context, string $categoryId): ?string
    {
        if (true !== $context->plugin?->categories || null === $context->target || !$context->target->isPage()) {
            return null;
        }

        return $this->urls->href(Target::category($context->target->id, $categoryId), $context->language);
    }

    /**
     * The page and the category of a target like "category:<page>-<category>".
     *
     * @return array{string, Category}|null
     */
    private function find(Target $target): ?array
    {
        if (!$target->isCategory()) {
            return null;
        }

        $parts = explode('-', $target->id, 2);
        $category = 2 === \count($parts) ? $this->categories->fetchById($parts[1]) : null;

        return null === $category ? null : [$parts[0], $category];
    }
}
