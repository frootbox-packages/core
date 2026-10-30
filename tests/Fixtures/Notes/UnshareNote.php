<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

#[AsAction('note.unshare', 'Withdraws the announcement of a note', undoable: false)]
final readonly class UnshareNote implements Action
{
    public function __construct(public int $id) {}

    public function handle(): void
    {
        Outbox::$messages[] = 'unshared #' . $this->id;
    }
}
