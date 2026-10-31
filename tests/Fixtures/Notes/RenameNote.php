<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Action<Note>
 */
#[AsAction('note.rename', 'Renames a note', permission: 'note.edit')]
final readonly class RenameNote implements Action, Scoped
{
    public function __construct(public int $id, public string $title) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [new Scope('note', (string) $this->id)];
    }

    public function handle(Notes $notes): Note
    {
        return $notes->update($this->id, ['title' => $this->title]);
    }
}
