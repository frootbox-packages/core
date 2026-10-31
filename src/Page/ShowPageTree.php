<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<list<PageTreeNode>>
 */
#[AsQuery('page.tree', 'Returns all pages as a tree with titles, slugs and status, drafts included')]
final readonly class ShowPageTree implements Query
{
    /**
     * @return list<PageTreeNode>
     */
    public function handle(PageReader $reader): array
    {
        return self::branch($reader->nodes(), null, []);
    }

    /**
     * @param array<string, PageNode> $nodes
     * @param array<string, true>     $path  Guards against loops in broken data
     *
     * @return list<PageTreeNode>
     */
    private static function branch(array $nodes, ?string $parentId, array $path): array
    {
        $branch = [];

        foreach (PageReader::children($nodes, $parentId) as $node) {
            if (isset($path[$node->id])) {
                continue;
            }

            $branch[] = new PageTreeNode(
                $node->id,
                $node->status,
                $node->data->layout,
                array_map(static fn(TranslationData $translation): string => $translation->title, $node->data->translations),
                array_map(static fn(TranslationData $translation): string => $translation->slug, $node->data->translations),
                self::branch($nodes, $node->id, $path + [$node->id => true]),
            );
        }

        return $branch;
    }
}
