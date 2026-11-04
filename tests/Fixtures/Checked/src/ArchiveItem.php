<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Checked;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * An action of acme/checked.
 *
 * @implements Action<bool>
 */
#[AsAction('acme/checked/item.archive', 'Archives an item')]
final readonly class ArchiveItem implements Action
{
    public function __construct(public int $id) {}

    public function handle(): bool
    {
        return true;
    }
}
