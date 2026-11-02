<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

/**
 * Work in the background, e.g. sending an order to a shop system
 * (decision 0221). Like an action, the constructor is the input and
 * handle() does the work with services passed by type. It runs with the
 * rights of whoever put it in the queue and changes data only through
 * actions.
 *
 *     #[AsJob('acme/shop/order.sync', 'Sends an order to the shop')]
 *     final readonly class SyncOrder implements Job
 *     {
 *         public function __construct(public int $order) {}
 *
 *         public function handle(ShopClient $client): void
 *         {
 *             $client->send($this->order);
 *         }
 *     }
 *
 * Put it in the queue with Queue::push(new SyncOrder(5)).
 */
interface Job {}
