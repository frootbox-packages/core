<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Language\Languages;

/**
 * Finds the published page for a path, segment by segment along the
 * slugs from the start page (decision 0104). Until the language URLs
 * arrive, only the default language without prefix.
 */
final readonly class PageResolver
{
    public function __construct(
        private PageReader $reader,
        private Languages $languages,
    ) {}

    public function resolve(string $path): ?ResolvedPage
    {
        $language = $this->languages->default()->code;
        $nodes = $this->reader->nodesAfterPublishing([]);
        $node = PageReader::children($nodes, null)[0] ?? null;
        $segments = '/' === $path ? [] : explode('/', trim($path, '/'));

        foreach ($segments as $segment) {
            if (null === $node) {
                return null;
            }

            $node = array_find(
                PageReader::children($nodes, $node->id),
                static fn(PageNode $child): bool => ($child->data->translations[$language] ?? null)?->slug === $segment,
            );
        }

        $translation = $node?->data->translations[$language] ?? null;

        if (null === $node || null === $translation) {
            return null;
        }

        return new ResolvedPage($node->id, $node->data, $language, $translation, '/' . implode('/', $segments));
    }
}
