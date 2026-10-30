<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Broken;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

#[AsAction('CreateThing', 'Broken')]
final readonly class BadName implements Action
{
    public function handle(): void {}
}
