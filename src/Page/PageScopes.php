<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Auth\ScopeResolver;

/**
 * Branches of the page tree as scopes (decision 0118): a role given for
 * "page:<id>" applies to that page and everything below, in the tree
 * with drafts.
 */
final readonly class PageScopes implements ScopeResolver
{
    public function __construct(private PageReader $reader) {}

    public function covers(string $scope, string $target, bool $strict): bool
    {
        $trail = self::trail($this->reader->nodes(), $target);

        if ($strict) {
            array_pop($trail);
        }

        return \in_array($scope, $trail, true);
    }

    public function exists(string $id): bool
    {
        return isset($this->reader->nodes()[$id]);
    }

    /**
     * Ids from the top down to the page itself, empty if it is unknown.
     *
     * @param array<string, PageNode> $nodes
     *
     * @return list<string>
     */
    public static function trail(array $nodes, string $id): array
    {
        $trail = [];

        for ($current = $id; null !== $current && isset($nodes[$current]) && !\in_array($current, $trail, true); $current = $nodes[$current]->data->parentId) {
            $trail[] = $current;
        }

        return array_reverse($trail);
    }
}
