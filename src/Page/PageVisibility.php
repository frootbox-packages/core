<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * What applies to a page (decision 0117), each the id of the page the
 * setting comes from, the page itself or one above it, null if it does
 * not apply.
 */
final readonly class PageVisibility
{
    public function __construct(
        /** Visitors get 503 instead of the page */
        public ?string $lockedBy = null,
        /** noindex for search engines, not in the sitemap */
        public ?string $excludedFromSearchEnginesBy = null,
        /** The site search leaves the page out */
        public ?string $excludedFromSiteSearchBy = null,
    ) {}

    /**
     * What applies to every page, from its own settings and those above.
     * The lock applies right away, so it follows the published tree; the
     * exclusions follow the tree as editors see it, drafts included.
     *
     * @param array<string, PageNode> $nodes     drafts over published state
     * @param array<string, PageNode> $published the published pages
     *
     * @return array<string, self> by id
     */
    public static function of(array $nodes, array $published): array
    {
        $visibility = [];

        foreach ($nodes as $id => $node) {
            $trail = self::trail($nodes, $id);
            $live = self::trail($published, $id);

            $visibility[$id] = new self(
                Reach::source(array_map(static fn(PageNode $node): Reach => $node->locked, $live)),
                Reach::source(array_map(static fn(PageNode $node): Reach => $node->data->excludeFromSearchEngines, $trail)),
                Reach::source(array_map(static fn(PageNode $node): Reach => $node->data->excludeFromSiteSearch, $trail)),
            );
        }

        return $visibility;
    }

    /**
     * The page and the pages above it, from the start page down.
     *
     * @param array<string, PageNode> $nodes
     *
     * @return array<string, PageNode>
     */
    private static function trail(array $nodes, string $id): array
    {
        $trail = [];

        for ($current = $id; null !== $current && isset($nodes[$current]) && !isset($trail[$current]); $current = $nodes[$current]->data->parentId) {
            $trail[$current] = $nodes[$current];
        }

        return array_reverse($trail, true);
    }
}
