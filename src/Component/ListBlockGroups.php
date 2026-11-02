<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\I18n\Labels;

/**
 * The groups of the block picker in their order (decision 0148).
 *
 * @implements Query<list<BlockGroup>>
 */
#[AsQuery('block.groups', 'Lists the groups of the block picker in their order, the core first', permission: 'page.tree')]
final readonly class ListBlockGroups implements Query, Scoped
{
    /**
     * Like block.list, a role within a branch is enough.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [];
    }

    /**
     * @return list<BlockGroup>
     */
    public function handle(BlockGroups $groups, Labels $labels): array
    {
        return array_map(static fn(BlockGroup $group): BlockGroup => new BlockGroup($group->name, $labels->text($group->title, Labels::packageOf($group->name))), $groups->all());
    }
}
