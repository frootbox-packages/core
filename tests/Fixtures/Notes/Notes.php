<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Database\Repository;

/**
 * @extends Repository<Note>
 */
final class Notes extends Repository
{
    protected function table(): string
    {
        return 'notes';
    }

    protected function entity(): string
    {
        return Note::class;
    }
}
