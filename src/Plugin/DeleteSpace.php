<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * @implements Action<bool>
 */
#[AsAction('space.delete', 'Deletes an empty space of a plugin that no page shows')]
final readonly class DeleteSpace implements Action
{
    public function __construct(public string $id) {}

    public function handle(SpaceEditor $editor): bool
    {
        $editor->delete('space.delete', $this->id);

        return true;
    }
}
