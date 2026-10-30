<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * Sends a mail: cannot be undone.
 */
#[AsAction('note.mail', 'Mails a note', undoable: false)]
final readonly class MailNote implements Action
{
    public function __construct(public int $id) {}

    public function handle(): void
    {
        Outbox::$messages[] = 'mail #' . $this->id;
    }
}
