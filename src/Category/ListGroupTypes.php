<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<list<array{type: string, label: string}>>
 */
#[AsQuery('category-group.types', 'The kinds of content a group of categories can apply to, each with its name', permission: 'category-group.create')]
final readonly class ListGroupTypes implements Query
{
    /**
     * @return list<array{type: string, label: string}>
     */
    public function handle(CategoryGroups $groups): array
    {
        return $groups->types();
    }
}
