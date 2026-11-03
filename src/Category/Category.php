<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A category in a group, below another one or at the top (decision
 * 0233). Title, slug and description by language; a category from a
 * preset of the configuration knows its key.
 */
final readonly class Category extends Entity
{
    /**
     * @param array<mixed> $translations {"de": {"title": "Kurse", "slug": "kurse", "description": "…"}}
     */
    public function __construct(
        public string $id,
        public string $groupKey,
        public ?string $parentId,
        public int $position,
        public array $translations,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public ?string $preset = null,
    ) {}

    public function title(string $language): ?string
    {
        return $this->text($language, 'title');
    }

    public function slug(string $language): ?string
    {
        return $this->text($language, 'slug');
    }

    public function description(string $language): ?string
    {
        return $this->text($language, 'description');
    }

    /**
     * The title in the first of these languages that has one, else in any.
     *
     * @param list<string> $languages
     */
    public function titleIn(array $languages): string
    {
        foreach ([...$languages, ...array_map(strval(...), array_keys($this->translations))] as $language) {
            $title = $this->title($language);

            if (null !== $title) {
                return $title;
            }
        }

        return $this->id;
    }

    private function text(string $language, string $key): ?string
    {
        $translation = $this->translations[$language] ?? null;
        $value = \is_array($translation) ? $translation[$key] ?? null : null;

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
