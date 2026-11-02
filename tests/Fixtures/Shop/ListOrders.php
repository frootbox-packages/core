<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Shop;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * A query of the extension package acme/shop.
 *
 * @implements Query<list<array{id: int, total: float}>>
 */
#[AsQuery('acme/shop/order.list', 'Lists orders')]
final readonly class ListOrders implements Query
{
    public function __construct(public int $limit = 10) {}

    /**
     * @return list<array{id: int, total: float}>
     */
    public function handle(): array
    {
        return \array_slice([['id' => 1, 'total' => 9.5], ['id' => 2, 'total' => 20.0]], 0, $this->limit);
    }
}
