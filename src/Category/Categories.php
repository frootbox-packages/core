<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Database\Repository;

/**
 * @extends Repository<Category>
 */
final class Categories extends Repository
{
    public const string TABLE = 'categories';

    /**
     * The categories of a group in the order of the tree: each one, then
     * the ones below it.
     *
     * @return list<CategoryItem>
     */
    public function tree(string $group): array
    {
        $children = [];

        foreach ($this->fetch(['where' => ['groupKey' => $group], 'order' => ['position', 'id']]) as $category) {
            $children[$category->parentId ?? ''][] = $category;
        }

        $items = [];
        $add = static function (string $parent, int $depth) use (&$add, &$items, $children): void {
            foreach ($children[$parent] ?? [] as $category) {
                $items[] = CategoryItem::of($category, $depth);
                $add($category->id, $depth + 1);
            }
        };
        $add('', 0);

        return $items;
    }

    /**
     * The categories directly below one, or at the top of a group.
     *
     * @return list<Category>
     */
    public function children(string $group, ?string $parentId): array
    {
        return $this->fetch(['where' => ['groupKey' => $group, 'parentId' => $parentId], 'order' => ['position', 'id']])->all();
    }

    /**
     * The ids of a category and of all below it.
     *
     * @return list<string>
     */
    public function withDescendants(Category $category): array
    {
        $ids = [$category->id];

        foreach ($this->tree($category->groupKey) as $item) {
            if (null !== $item->parentId && \in_array($item->parentId, $ids, true)) {
                $ids[] = $item->id;
            }
        }

        return $ids;
    }

    /**
     * The category of a group with this slug in a language.
     */
    public function bySlug(string $group, string $language, string $slug): ?Category
    {
        foreach ($this->fetch(['where' => ['groupKey' => $group]]) as $category) {
            if ($category->slug($language) === $slug) {
                return $category;
            }
        }

        return null;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Category::class;
    }
}
