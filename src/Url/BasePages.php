<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Page\PageReader;

/**
 * The first published page in the tree whose content holds one of the
 * blocks, in each language, e.g. the page with the search for a link in
 * the head (decision 0228). Plugins have pages of their own (decision 0232).
 */
final readonly class BasePages
{
    public function __construct(
        private PagePaths $paths,
        private PageReader $reader,
    ) {}

    /**
     * @param list<string> $blocks
     *
     * @return array<string, string> Page id by language
     */
    public function of(array $blocks): array
    {
        $bases = [];

        foreach ($this->paths->all() as $path) {
            if (isset($bases[$path->language])) {
                continue;
            }

            $content = $this->reader->published($path->pageId)?->translations[$path->contentLanguage]->content ?? [];

            if ([] !== array_intersect($blocks, self::blocks($content))) {
                $bases[$path->language] = $path->pageId;
            }
        }

        return $bases;
    }

    /**
     * The names of all blocks in a content, nested ones included.
     *
     * @param array<mixed> $node
     *
     * @return list<string>
     */
    public static function blocks(array $node): array
    {
        $names = [];

        foreach (\is_array($node['areas'] ?? null) ? $node['areas'] : [] as $list) {
            foreach (\is_array($list) ? $list : [] as $block) {
                if (\is_array($block)) {
                    if (\is_string($block['block'] ?? null)) {
                        $names[] = $block['block'];
                    }

                    $names = [...$names, ...self::blocks($block)];
                }
            }
        }

        return $names;
    }
}
