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
     * @param list<string>                       $blocks
     * @param (callable(array<mixed>): bool)|null $match  only blocks it accepts, e.g. by their options (decision 0360)
     *
     * @return array<string, string> Page id by language
     */
    public function of(array $blocks, ?callable $match = null): array
    {
        $bases = [];

        foreach ($this->paths->all() as $path) {
            if (isset($bases[$path->language])) {
                continue;
            }

            $content = $this->reader->published($path->pageId)?->translations[$path->contentLanguage]->content ?? [];
            $found = array_filter(self::nodes($content), static fn(array $block): bool => \in_array($block['block'] ?? null, $blocks, true) && (null === $match || $match($block)));

            if ([] !== $found) {
                $bases[$path->language] = $path->pageId;
            }
        }

        return $bases;
    }

    /**
     * All blocks in a content, nested ones included, with options and fields.
     *
     * @param array<mixed> $node
     *
     * @return list<array<mixed>> each with the name in "block"
     */
    public static function nodes(array $node): array
    {
        $nodes = [];

        foreach (\is_array($node['areas'] ?? null) ? $node['areas'] : [] as $list) {
            foreach (\is_array($list) ? $list : [] as $block) {
                if (\is_array($block)) {
                    if (\is_string($block['block'] ?? null)) {
                        $nodes[] = $block;
                    }

                    $nodes = [...$nodes, ...self::nodes($block)];
                }
            }
        }

        return $nodes;
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
