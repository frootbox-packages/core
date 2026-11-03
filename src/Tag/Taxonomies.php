<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Category\CategoryGroups;
use Frootbox\Core\Category\ObjectCategories;

/**
 * Categories and tags of a published object for templates (decisions
 * 0233, 0239): categories(), tags() and has_tag() in Twig.
 */
final readonly class Taxonomies
{
    public function __construct(
        private ObjectTags $tags,
        private ObjectCategories $categories,
        private CategoryGroups $groups,
    ) {}

    /**
     * The tags visitors may see, with label and slug for ?tag=.
     *
     * @param list<string> $languages The language and its fallbacks
     *
     * @return list<array{key: string, label: string, slug: string}>
     */
    public function tags(string $type, string $id, array $languages): array
    {
        $result = [];

        foreach ($this->tags->of($type, [$id])[$id] ?? [] as $tag) {
            if (!$tag->internal) {
                $result[] = ['key' => $tag->key, 'label' => $tag->labelIn($languages), 'slug' => $tag->slugIn($languages)];
            }
        }

        return $result;
    }

    /**
     * Whether an object has a tag, internal ones included, by its key.
     */
    public function hasTag(string $type, string $id, string $key): bool
    {
        return null !== array_find($this->tags->of($type, [$id])[$id] ?? [], static fn(Tag $tag): bool => $tag->key === $key);
    }

    /**
     * The categories of an object in the order of the trees, with title,
     * slug for ?category= and the key of their group; with a group only
     * those of the group.
     *
     * @param list<string> $languages The language and its fallbacks
     *
     * @return list<array{id: string, title: string, slug: string|null, group: string, preset: string|null}>
     */
    public function categories(string $type, string $id, array $languages, ?string $group = null): array
    {
        if (null !== $group && null === $this->groups->get($group)) {
            return [];
        }

        $result = [];

        foreach ($this->categories->of($type, [$id], $group)[$id] ?? [] as $category) {
            $slug = null;

            foreach ($languages as $language) {
                $slug ??= null === $category->title($language) ? null : $category->slug($language);
            }

            $result[] = ['id' => $category->id, 'title' => $category->titleIn($languages), 'slug' => $slug, 'group' => $category->groupKey, 'preset' => $category->preset];
        }

        return $result;
    }
}
