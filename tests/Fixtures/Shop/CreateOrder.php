<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Shop;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * Forgot the package name in front.
 *
 * @implements Action<null>
 */
#[AsAction('order.create', 'Creates an order')]
final readonly class CreateOrder implements Action
{
    public function handle(): null
    {
        return null;
    }
}
