<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Language\Languages;

/**
 * The tags as choices of a block option (decision 0239), e.g. the newest
 * articles with one tag only: "Any" first, then each tag by its key, so
 * the choice holds in every language. Internal tags are marked.
 *
 * @implements Query<list<array{value: string, label: string}>>
 */
#[AsQuery('tag.options', 'The tags as choices for a block, "Any" first; the value is the key of the tag', signedIn: true)]
final readonly class TagOptions implements Query
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function handle(Tags $tags, Languages $languages, Translator $translator): array
    {
        $codes = $languages->contentLanguages($languages->default()->code);
        $options = [];

        foreach ($tags->current() as $tag) {
            $label = $tag->labelIn($codes);
            $options[] = ['value' => $tag->key, 'label' => $tag->internal ? $translator->get('{tag} (internal)', ['tag' => $label]) : $label];
        }

        usort($options, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['label']), mb_strtolower($b['label'])));

        return [] === $options ? [] : [['value' => '', 'label' => $translator->get('Any')], ...$options];
    }
}
