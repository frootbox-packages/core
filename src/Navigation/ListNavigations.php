<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<list<NavigationDetails>>
 */
#[AsQuery('navigation.list', 'Lists the navigations of the theme with their draft and published items')]
final readonly class ListNavigations implements Query
{
    /**
     * @return list<NavigationDetails>
     */
    public function handle(Definitions $definitions, NavigationReader $reader): array
    {
        return array_values(array_map($reader->details(...), $definitions->all()));
    }
}
