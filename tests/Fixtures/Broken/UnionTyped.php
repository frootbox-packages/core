<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Broken;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

#[AsAction('broken.union', 'Broken')]
final readonly class UnionTyped implements Action
{
    public function __construct(public int|string $id) {}

    public function handle(): void {}
}
