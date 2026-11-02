<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Database\Result;

/**
 * @implements Query<Result<Note>>
 */
#[AsQuery('note.list', 'Lists notes', public: true)]
final readonly class ListNotes implements Query
{
    public function __construct(public ?NoteStatus $status = null) {}

    /**
     * @return Result<Note>
     */
    public function handle(Notes $notes): Result
    {
        return $notes->fetch(['where' => null === $this->status ? [] : ['status' => $this->status], 'order' => 'id']);
    }
}
