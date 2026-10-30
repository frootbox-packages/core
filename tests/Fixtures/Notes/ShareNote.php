<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Compensations;

/**
 * Pins a note and announces it: the announcement is made up for by
 * UnshareNote when the action is undone.
 */
#[AsAction('note.share', 'Pins and announces a note', undoable: false)]
final readonly class ShareNote implements Action
{
    public function __construct(public int $id) {}

    public function handle(Notes $notes, Compensations $compensations): void
    {
        $notes->update($this->id, ['pinned' => true]);
        Outbox::$messages[] = 'shared #' . $this->id;
        $compensations->add(new UnshareNote($this->id));
    }
}
