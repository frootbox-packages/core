<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * A query that tries to write: not allowed.
 */
#[AsQuery('note.write-in-query', 'Tries to write in a query')]
final readonly class WriteInQuery implements Query
{
    public function handle(Notes $notes): void
    {
        $notes->insert(['title' => 'sneaky']);
    }
}
