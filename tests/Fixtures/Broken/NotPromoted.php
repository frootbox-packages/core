<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Broken;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

#[AsAction('broken.not-promoted', 'Broken')]
final readonly class NotPromoted implements Action
{
    public string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function handle(): void {}
}
