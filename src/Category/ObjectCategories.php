<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Support\Message;

/**
 * The categories of objects for modules (decision 0233). A module takes
 * the ids in its actions (check()), keeps them in the draft and passes
 * them on when it publishes (sync()) or takes an object offline
 * (clear()). Lists read them with of().
 */
final readonly class ObjectCategories
{
    public function __construct(
        private Categories $categories,
        private CategoryLinks $links,
        private CategoryGroups $groups,
    ) {}

    /**
     * The ids of an action's input, each must be a category of a group
     * for the type.
     *
     * @param list<mixed> $ids
     *
     * @return list<string>
     *
     * @throws InvalidInputException
     */
    public function check(string $action, string $type, array $ids, string $pointer = '/categories'): array
    {
        $errors = [];
        $result = [];

        foreach ($ids as $index => $id) {
            $category = \is_string($id) ? $this->categories->fetchById($id) : null;

            if (null === $category) {
                $errors[$pointer . '/' . $index] = new Message('There is no category {id}.', ['id' => \is_scalar($id) ? (string) $id : '']);
            } elseif (true !== $this->groups->get($category->groupKey)?->appliesTo($type)) {
                $errors[$pointer . '/' . $index] = new Message('The category {id} is in a group that does not apply here.', ['id' => $category->id]);
            } elseif (!\in_array($category->id, $result, true)) {
                $result[] = $category->id;
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $result;
    }

    /**
     * Sets the categories of a published object; ids of categories that
     * are gone or whose group no longer applies are left out.
     *
     * @param list<string> $ids
     */
    public function sync(string $type, string $id, array $ids): void
    {
        $wanted = [];

        foreach (array_unique($ids) as $categoryId) {
            $category = $this->categories->fetchById($categoryId);

            if (null !== $category && true === $this->groups->get($category->groupKey)?->appliesTo($type)) {
                $wanted[] = $category->id;
            }
        }

        foreach ($this->links->fetch(['where' => ['targetType' => $type, 'targetId' => $id]]) as $link) {
            if (\in_array($link->categoryId, $wanted, true)) {
                $wanted = array_values(array_diff($wanted, [$link->categoryId]));
            } else {
                $this->links->delete($link->id);
            }
        }

        foreach ($wanted as $categoryId) {
            $this->links->insert(['categoryId' => $categoryId, 'targetType' => $type, 'targetId' => $id]);
        }
    }

    public function clear(string $type, string $id): void
    {
        foreach ($this->links->fetch(['where' => ['targetType' => $type, 'targetId' => $id]]) as $link) {
            $this->links->delete($link->id);
        }
    }

    /**
     * The categories of published objects by object, in the order of
     * their groups and trees; with a group only those of the group.
     *
     * @param list<string> $ids
     *
     * @return array<string, list<Category>>
     */
    public function of(string $type, array $ids, ?string $group = null): array
    {
        $assigned = $this->links->idsOf($type, $ids);

        if ([] === $assigned) {
            return [];
        }

        $order = $this->order($type, $group);
        $byId = [];

        foreach ($this->categories->fetch(['where' => ['id' => array_values(array_unique(array_merge(...array_values($assigned))))]]) as $category) {
            $byId[$category->id] = $category;
        }

        $result = [];

        foreach ($assigned as $target => $categoryIds) {
            $list = array_values(array_filter(array_map(static fn(string $id): ?Category => isset($order[$id]) ? $byId[$id] ?? null : null, $categoryIds)));
            usort($list, static fn(Category $a, Category $b): int => $order[$a->id] <=> $order[$b->id]);
            $result[$target] = $list;
        }

        return $result;
    }

    /**
     * The categories of the groups of a type in the order of the trees,
     * each with its depth; with a group only that one.
     *
     * @return list<CategoryItem>
     */
    public function tree(string $type, ?string $group = null): array
    {
        $items = [];

        foreach ($this->groups->forType($type) as $candidate) {
            if (null === $group || $candidate->key === $group) {
                $items = [...$items, ...$this->categories->tree($candidate->key)];
            }
        }

        return $items;
    }

    /**
     * The category of the groups of a type with this slug in a language,
     * with the ids of all below it, e.g. for ?category=kurse in a list.
     *
     * @return array{Category, list<string>}|null
     */
    public function bySlug(string $type, string $language, string $slug, ?string $group = null): ?array
    {
        foreach ($this->groups->forType($type) as $candidate) {
            if (null !== $group && $candidate->key !== $group) {
                continue;
            }

            $category = $this->categories->bySlug($candidate->key, $language, $slug);

            if (null !== $category) {
                return [$category, $this->categories->withDescendants($category)];
            }
        }

        return null;
    }

    /**
     * @return array<string, int> place by id
     */
    private function order(string $type, ?string $group): array
    {
        $order = [];

        foreach ($this->tree($type, $group) as $index => $item) {
            $order[$item->id] = $index;
        }

        return $order;
    }
}
