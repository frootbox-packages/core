<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Checked;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * A query of acme/checked.
 *
 * @implements Query<list<string>>
 */
#[AsQuery('acme/checked/item.list', 'Lists items')]
final readonly class ListItems implements Query
{
    /**
     * @return list<string>
     */
    public function handle(): array
    {
        return [];
    }
}
