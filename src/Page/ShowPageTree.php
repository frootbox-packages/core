<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Query<list<PageTreeNode>>
 */
#[AsQuery('page.tree', 'Returns all pages as a tree with titles, slugs and status, drafts included; with a role only within branches those and the way to them')]
final readonly class ShowPageTree implements Query, Scoped
{
    /**
     * A role within a branch is enough to see the pages of the branch and the way to it.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [];
    }

    /**
     * @return list<PageTreeNode>
     */
    public function handle(PageReader $reader, Gate $gate, CurrentContext $context): array
    {
        $nodes = $reader->nodes();
        $within = $gate->within($context->get()->actor, 'page.tree', 'page');

        return self::branch($nodes, $reader->visibility($nodes), null === $within ? null : self::shown($nodes, $within), null, []);
    }

    /**
     * The pages of the branches and the way to them; true for the ones on
     * the way only.
     *
     * @param array<string, PageNode> $nodes
     * @param list<string>            $branches
     *
     * @return array<string, bool> by id
     */
    private static function shown(array $nodes, array $branches): array
    {
        $shown = [];

        foreach (array_keys($nodes) as $id) {
            $trail = PageScopes::trail($nodes, $id);

            foreach ($branches as $branch) {
                $at = array_search($branch, $trail, true);

                if (false !== $at) {
                    $shown[$id] = false;

                    foreach (\array_slice($trail, 0, $at) as $above) {
                        $shown[$above] ??= true;
                    }
                }
            }
        }

        return $shown;
    }

    /**
     * @param array<string, PageNode>       $nodes
     * @param array<string, PageVisibility> $visibility
     * @param array<string, bool>|null      $shown      null for all pages, see shown()
     * @param array<string, true>           $path       Guards against loops in broken data
     *
     * @return list<PageTreeNode>
     */
    private static function branch(array $nodes, array $visibility, ?array $shown, ?string $parentId, array $path): array
    {
        $branch = [];

        foreach (PageReader::children($nodes, $parentId) as $node) {
            if (isset($path[$node->id]) || (null !== $shown && !isset($shown[$node->id]))) {
                continue;
            }

            $branch[] = new PageTreeNode(
                $node->id,
                $node->status,
                $node->data->layout,
                array_map(static fn(TranslationData $translation): string => $translation->title, $node->data->translations),
                array_map(static fn(TranslationData $translation): string => $translation->slug, $node->data->translations),
                self::branch($nodes, $visibility, $shown, $node->id, $path + [$node->id => true]),
                $node->publishAt,
                $node->locked,
                $visibility[$node->id] ?? new PageVisibility(),
                $shown[$node->id] ?? false,
            );
        }

        return $branch;
    }
}
