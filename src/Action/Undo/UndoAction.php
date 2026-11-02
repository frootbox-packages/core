<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Undo;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Action<array{action: string, changes: int}>
 */
#[AsAction('action.undo', 'Undoes an earlier action: restores the data it changed and runs its compensations. Fails if that data has changed since.')]
final readonly class UndoAction implements Action, Scoped
{
    public function __construct(
        #[Description('ID of the action in the action log')]
        public int $id,
    ) {}

    /**
     * The targets of the undone action are checked by the Undoer.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [];
    }

    /**
     * @return array{action: string, changes: int}
     */
    public function handle(Undoer $undoer): array
    {
        return $undoer->undo($this->id);
    }
}
