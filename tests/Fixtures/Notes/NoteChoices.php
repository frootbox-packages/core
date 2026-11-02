<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * The notes to choose in an option of a block.
 *
 * @implements Query<list<array{value: string, label: string}>>
 */
#[AsQuery('note.choices', 'Lists the notes as choices', permission: 'page.tree')]
final readonly class NoteChoices implements Query
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function handle(Notes $notes): array
    {
        $choices = [];

        foreach ($notes->fetch(['order' => 'id']) as $note) {
            $choices[] = ['value' => (string) $note->id, 'label' => $note->title];
        }

        return $choices;
    }
}
