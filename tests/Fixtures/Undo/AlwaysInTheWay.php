<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Undo;

use Frootbox\Core\Action\Change;
use Frootbox\Core\Action\Undo\UndoCheck;
use Frootbox\Core\Action\Undo\UndoConflict;

final readonly class AlwaysInTheWay implements UndoCheck
{
    /**
     * @param list<Change> $changes
     */
    public function check(array $changes): array
    {
        return [new UndoConflict('Always in the way.', ['things' => [1]])];
    }
}
