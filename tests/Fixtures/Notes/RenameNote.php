<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * @implements Action<Note>
 */
#[AsAction('note.rename', 'Renames a note', permission: 'note.edit')]
final readonly class RenameNote implements Action
{
    public function __construct(public int $id, public string $title) {}

    public function handle(Notes $notes): Note
    {
        return $notes->update($this->id, ['title' => $this->title]);
    }
}
