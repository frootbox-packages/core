<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\Slug;
use Frootbox\Core\Support\Message;

/**
 * Writes categories (decision 0233). They have no draft: changes apply
 * at once and can be undone. Slugs are unique per group and language,
 * a category moves only within its group and never below itself.
 */
final readonly class CategoryEditor
{
    public const int TITLE_LENGTH = 100;

    public const int DESCRIPTION_LENGTH = 1000;

    public function __construct(
        private Categories $categories,
        private CategoryLinks $links,
        private CategoryGroups $groups,
        private Languages $languages,
    ) {}

    /**
     * @param array<mixed> $translations
     *
     * @throws InvalidInputException
     */
    public function create(string $action, string $group, array $translations, ?string $parentId, ?int $position): Category
    {
        if (null === $this->groups->get($group)) {
            throw new InvalidInputException($action, ['/group' => new Message('There is no group of categories "{group}".', ['group' => $group])]);
        }

        $this->parent($action, $group, $parentId, null);
        $checked = $this->translations($action, $group, null, $translations, []);
        $siblings = $this->categories->children($group, $parentId);
        $place = null === $position ? \count($siblings) : min($position, \count($siblings));
        $this->renumber(array_merge(\array_slice($siblings, 0, $place), [null], \array_slice($siblings, $place)));

        return $this->categories->insert(['groupKey' => $group, 'parentId' => $parentId, 'position' => $place, 'translations' => $checked]);
    }

    /**
     * @param array<mixed> $translations
     *
     * @throws InvalidInputException
     */
    public function update(string $action, string $id, array $translations): Category
    {
        $category = $this->find($action, $id);

        return $this->categories->update($category->id, ['translations' => $this->translations($action, $category->groupKey, $category->id, $translations, $category->translations)]);
    }

    /**
     * Puts a category below another one of its group or at the top, at a
     * place among the new siblings (null for the last).
     *
     * @throws InvalidInputException
     */
    public function move(string $action, string $id, ?string $parentId, ?int $position): Category
    {
        $category = $this->find($action, $id);
        $this->parent($action, $category->groupKey, $parentId, $category);

        $old = array_values(array_filter($this->categories->children($category->groupKey, $category->parentId), static fn(Category $other): bool => $other->id !== $category->id));
        $siblings = $category->parentId === $parentId ? $old : $this->categories->children($category->groupKey, $parentId);
        $place = null === $position ? \count($siblings) : min($position, \count($siblings));

        if ($category->parentId !== $parentId) {
            $this->renumber($old);
        }

        $this->renumber(array_merge(\array_slice($siblings, 0, $place), [null], \array_slice($siblings, $place)));

        return $this->categories->update($category->id, ['parentId' => $parentId, 'position' => $place]);
    }

    /**
     * Deletes a category with all below it; the objects stay, only
     * without them.
     *
     * @throws InvalidInputException
     */
    public function delete(string $action, string $id): void
    {
        $category = $this->find($action, $id);
        $ids = $this->categories->withDescendants($category);

        foreach ($this->links->fetch(['where' => ['categoryId' => $ids]]) as $link) {
            $this->links->delete($link->id);
        }

        foreach (array_reverse($ids) as $each) {
            $this->categories->delete($each);
        }

        $this->renumber($this->categories->children($category->groupKey, $category->parentId));
    }

    /**
     * Creates the presets of a group that have no category yet, below
     * their parents and after the categories there. A category with the
     * same slug takes the preset instead. Returns how many it created.
     *
     * @param list<CategoryPreset>|null $presets
     */
    public function applyPresets(CategoryGroup $group, ?array $presets = null, ?string $parentId = null): int
    {
        $created = 0;

        foreach ($presets ?? $group->presets as $preset) {
            $category = $this->categories->byPreset($group->key, $preset->key) ?? $this->adopt($group->key, $preset);

            if (null === $category) {
                $translations = [];

                foreach ($preset->titles as $language => $title) {
                    if ($this->languages->has($language)) {
                        $translations[$language] = ['title' => $title, 'slug' => Slug::fromTitle($title, $language)];
                    }
                }

                $category = $this->categories->insert(['groupKey' => $group->key, 'parentId' => $parentId, 'position' => \count($this->categories->children($group->key, $parentId)), 'translations' => $translations, 'preset' => $preset->key]);
                ++$created;
            }

            $created += $this->applyPresets($group, $preset->children, $category->id);
        }

        return $created;
    }

    /**
     * How many presets of a group have no category yet.
     *
     * @param list<CategoryPreset>|null $presets
     */
    public function missingPresets(CategoryGroup $group, ?array $presets = null): int
    {
        $missing = 0;

        foreach ($presets ?? $group->presets as $preset) {
            $missing += (null === $this->categories->byPreset($group->key, $preset->key) ? 1 : 0) + $this->missingPresets($group, $preset->children);
        }

        return $missing;
    }

    /**
     * A category without a preset whose slug is the one of the preset in
     * a language, e.g. created by hand before the preset came.
     */
    private function adopt(string $group, CategoryPreset $preset): ?Category
    {
        foreach ($preset->titles as $language => $title) {
            $category = $this->categories->bySlug($group, $language, Slug::fromTitle($title, $language));

            if (null !== $category && null === $category->preset) {
                return $this->categories->update($category->id, ['preset' => $preset->key]);
            }
        }

        return null;
    }

    /**
     * @param list<Category|null> $list The siblings in their new order, null for the one placed
     */
    private function renumber(array $list): void
    {
        foreach ($list as $position => $sibling) {
            if (null !== $sibling && $sibling->position !== $position) {
                $this->categories->update($sibling->id, ['position' => $position]);
            }
        }
    }

    private function find(string $action, string $id): Category
    {
        return $this->categories->fetchById($id) ?? throw new InvalidInputException($action, ['/id' => 'There is no category with this id.']);
    }

    private function parent(string $action, string $group, ?string $parentId, ?Category $moved): void
    {
        if (null === $parentId) {
            return;
        }

        $parent = $this->categories->fetchById($parentId);

        if (null === $parent || $parent->groupKey !== $group) {
            throw new InvalidInputException($action, ['/parentId' => 'There is no such category in the group.']);
        }

        if (null !== $moved && \in_array($parent->id, $this->categories->withDescendants($moved), true)) {
            throw new InvalidInputException($action, ['/parentId' => 'A category cannot go below itself.']);
        }
    }

    /**
     * Title, slug and description by language. Languages left out stay,
     * null or an empty title and slug remove one; the slug comes from the
     * title if left out.
     *
     * @param array<mixed> $input
     * @param array<mixed> $current
     *
     * @return array<string, array{title: string, slug: string, description?: string}>
     *
     * @throws InvalidInputException
     */
    private function translations(string $action, string $group, ?string $id, array $input, array $current): array
    {
        if ([] !== $input && array_is_list($input)) {
            throw new InvalidInputException($action, ['/translations' => 'must be an object by language code, e.g. {"de": {"title": "Kurse"}}.']);
        }

        $errors = [];
        $result = [];

        foreach ($current as $language => $value) {
            if (\is_array($value) && \is_string($value['title'] ?? null) && \is_string($value['slug'] ?? null)) {
                $result[(string) $language] = ['title' => $value['title'], 'slug' => $value['slug']] + (\is_string($value['description'] ?? null) && '' !== $value['description'] ? ['description' => $value['description']] : []);
            }
        }

        foreach ($input as $language => $value) {
            $language = (string) $language;
            $at = '/translations/' . $language;
            $title = \is_array($value) && \is_string($value['title'] ?? null) ? trim($value['title']) : '';
            $slug = \is_array($value) && \is_string($value['slug'] ?? null) ? trim($value['slug']) : '';
            $description = \is_array($value) && \is_string($value['description'] ?? null) ? trim($value['description']) : '';

            // A form sends every language; one left empty is none.
            if (null === $value || ('' === $title && '' === $slug && '' === $description)) {
                unset($result[$language]);

                continue;
            }

            if (!$this->languages->has($language)) {
                $errors[$at] = new Message('"{language}" is no language of the website.', ['language' => $language]);

                continue;
            }

            $slug = '' !== $slug ? $slug : Slug::fromTitle($title, $language);

            if ('' === $title) {
                $errors[$at . '/title'] = 'is required.';
            } elseif (mb_strlen($title) > self::TITLE_LENGTH) {
                $errors[$at . '/title'] = new Message('must be at most {max} characters long.', ['max' => self::TITLE_LENGTH]);
            }

            if (mb_strlen($description) > self::DESCRIPTION_LENGTH) {
                $errors[$at . '/description'] = new Message('must be at most {max} characters long.', ['max' => self::DESCRIPTION_LENGTH]);
            }

            if (1 !== preg_match('/' . Slug::PATTERN . '/u', $slug) || mb_strlen($slug) > Slug::MAX_LENGTH) {
                $errors[$at . '/slug'] = 'must be lower-case letters, digits and hyphens, e.g. "kurse".';
            } else {
                $other = $this->categories->bySlug($group, $language, $slug);

                if (null !== $other && $other->id !== $id) {
                    $errors[$at . '/slug'] = new Message('Another category of the group has the slug "{slug}" already.', ['slug' => $slug]);
                }
            }

            $result[$language] = ['title' => $title, 'slug' => $slug] + ('' === $description ? [] : ['description' => $description]);
        }

        if ([] === $result) {
            $first = array_key_first($input);
            $errors[null === $first ? '/translations' : '/translations/' . $first . '/title'] ??= null === $first ? 'needs at least one language, e.g. {"de": {"title": "Kurse"}}.' : 'is required.';
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $result;
    }
}
