<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Language\Languages;

/**
 * @implements Query<list<TagSummary>>
 */
#[AsQuery('tag.list', 'The tags of the common stock by label, with how many published objects have each', signedIn: true)]
final readonly class ListTags implements Query
{
    public function __construct(
        #[Length(max: 60)]
        #[Description('Only tags whose key or a label contains this')]
        public string $query = '',
        #[Description('Count only objects of this type, e.g. "frootbox/jobs/job"')]
        public ?string $type = null,
    ) {}

    /**
     * @return list<TagSummary>
     */
    public function handle(Tags $tags, TagLinks $links, TagPresets $presets, Languages $languages): array
    {
        $counts = $links->counts($this->type);
        $keys = array_map(static fn(TagPreset $preset): string => $preset->key, $presets->all());
        $needle = mb_strtolower(trim($this->query));
        $language = [$languages->default()->code];
        $result = [];

        foreach ($tags->current() as $tag) {
            $labels = array_filter($tag->labels, \is_string(...));

            if ('' !== $needle && !str_contains($tag->key, $needle) && [] === array_filter($labels, static fn(string $label): bool => str_contains(mb_strtolower($label), $needle))) {
                continue;
            }

            $result[] = new TagSummary($tag->id, $tag->key, array_combine(array_map(strval(...), array_keys($labels)), array_values($labels)), $tag->internal, \in_array($tag->key, $keys, true), $counts[$tag->id] ?? 0);
        }

        usort($result, static fn(TagSummary $a, TagSummary $b): int => strcmp(self::sortLabel($a, $language), self::sortLabel($b, $language)));

        return $result;
    }

    /**
     * @param list<string> $languages
     */
    private static function sortLabel(TagSummary $tag, array $languages): string
    {
        return mb_strtolower($tag->labels[$languages[0]] ?? array_values($tag->labels)[0] ?? $tag->key);
    }
}
