<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * @implements Action<bool>
 */
#[AsAction('note.delete', 'Deletes a note')]
final readonly class DeleteNote implements Action
{
    public function __construct(public int $id) {}

    public function handle(Notes $notes): bool
    {
        return $notes->delete($this->id);
    }
}
