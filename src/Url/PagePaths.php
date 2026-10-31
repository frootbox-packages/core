<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\PageNode;
use Frootbox\Core\Page\PageReader;

/**
 * Works out the paths of all published pages in every language from the
 * tree (decision 0105): the slugs from the start page down. A page
 * without translation takes slug and content of the fallback language;
 * without either it has no path in that language, and neither have its
 * subpages.
 */
final readonly class PagePaths
{
    public function __construct(
        private PageReader $reader,
        private Languages $languages,
    ) {}

    /**
     * @return list<PagePath>
     */
    public function all(): array
    {
        $nodes = $this->reader->nodesAfterPublishing([]);
        $children = [];

        foreach ($nodes as $node) {
            $children[$node->data->parentId ?? ''][] = $node;
        }

        $paths = [];

        foreach ($this->languages->codes() as $language) {
            $chain = $this->languages->contentLanguages($language);

            foreach ($children[''] ?? [] as $start) {
                array_push($paths, ...self::walk($start, '', true, $language, $chain, $children));
            }
        }

        return $paths;
    }

    /**
     * @param non-empty-list<string>        $chain
     * @param array<string, list<PageNode>> $children
     *
     * @return list<PagePath>
     */
    private static function walk(PageNode $node, string $parentPath, bool $isStart, string $language, array $chain, array $children): array
    {
        $content = self::contentLanguage($node, $chain);

        if (null === $content) {
            return [];
        }

        $path = $isStart ? '' : ltrim($parentPath . '/' . $node->data->translations[$content]->slug, '/');
        $paths = [new PagePath($node->id, $language, $path, $content)];

        // A slug from a fallback can meet an own slug of a sibling: the own one wins.
        $used = [];
        $siblings = $children[$node->id] ?? [];
        usort($siblings, static fn(PageNode $a, PageNode $b): int => array_search(self::contentLanguage($a, $chain), $chain, true) <=> array_search(self::contentLanguage($b, $chain), $chain, true));

        foreach ($siblings as $child) {
            $childContent = self::contentLanguage($child, $chain);

            if (null === $childContent || isset($used[$child->data->translations[$childContent]->slug])) {
                continue;
            }

            $used[$child->data->translations[$childContent]->slug] = true;
            array_push($paths, ...self::walk($child, $path, false, $language, $chain, $children));
        }

        return $paths;
    }

    /**
     * @param non-empty-list<string> $chain
     */
    private static function contentLanguage(PageNode $node, array $chain): ?string
    {
        return array_find($chain, static fn(string $code): bool => isset($node->data->translations[$code]));
    }
}
