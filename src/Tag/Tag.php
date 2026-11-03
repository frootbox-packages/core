<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use DateTimeImmutable;
use Frootbox\Core\Page\Slug;
use Frootbox\Database\Entity;

/**
 * A tag of the common stock (decision 0233): a fixed key and a label by
 * language. An internal tag selects and switches, but visitors never
 * see it. A tag merged into another one points there.
 */
final readonly class Tag extends Entity
{
    /**
     * @param array<mixed> $labels {"de": "Nachhaltigkeit", "en": "Sustainability"}
     */
    public function __construct(
        public string $id,
        public string $key,
        public array $labels,
        public bool $internal,
        public ?string $mergedInto,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    public function label(string $language): ?string
    {
        $label = $this->labels[$language] ?? null;

        return \is_string($label) && '' !== $label ? $label : null;
    }

    /**
     * The label in the first of these languages that has one, else in
     * any, else the key.
     *
     * @param list<string> $languages
     */
    public function labelIn(array $languages): string
    {
        foreach ([...$languages, ...array_map(strval(...), array_keys($this->labels))] as $language) {
            $label = $this->label($language);

            if (null !== $label) {
                return $label;
            }
        }

        return $this->key;
    }

    /**
     * The tag in the address of a filter, e.g. ?tag=sustainability: the
     * label of the first of these languages that has one as a slug.
     *
     * @param list<string> $languages
     */
    public function slugIn(array $languages): string
    {
        foreach ($languages as $language) {
            $label = $this->label($language);

            if (null !== $label) {
                return Slug::fromTitle($label, $language);
            }
        }

        return $this->key;
    }

    /**
     * Whether a text names this tag: its key or one of its labels,
     * without regard to case.
     */
    public function isNamed(string $text): bool
    {
        $text = mb_strtolower(trim($text));

        if ($text === $this->key) {
            return true;
        }

        foreach ($this->labels as $label) {
            if (\is_string($label) && mb_strtolower($label) === $text) {
                return true;
            }
        }

        return false;
    }
}
