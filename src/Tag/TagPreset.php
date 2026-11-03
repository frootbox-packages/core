<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

/**
 * A tag an agency or an extension sets out (decision 0233): suggested
 * everywhere, for a type of object, or for one space of a type. The
 * tag is created when someone chooses it.
 *
 *     $registry->add(TagPreset::class, new TagPreset('featured', internal: true, type: 'frootbox/news/article'));
 */
final readonly class TagPreset
{
    /**
     * @param array<string, string> $labels By language, e.g. ["de" => "Pflege"]
     * @param string|null           $space  Id or name of a space of the type
     */
    public function __construct(
        public string $key,
        public array $labels = [],
        public bool $internal = false,
        public ?string $type = null,
        public ?string $space = null,
    ) {}

    /**
     * Whether a text names this preset: its key or one of its labels,
     * without regard to case.
     */
    public function isNamed(string $text): bool
    {
        $text = mb_strtolower(trim($text));

        return $text === $this->key || \in_array($text, array_map(mb_strtolower(...), array_values($this->labels)), true);
    }
}
