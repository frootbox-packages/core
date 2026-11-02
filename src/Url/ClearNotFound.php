<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * The log is no content, so clearing it cannot be undone (decision 0105).
 *
 * @implements Action<int>
 */
#[AsAction('not-found.clear', 'Empties the log of paths that found nothing and returns how many were removed', undoable: false)]
final readonly class ClearNotFound implements Action
{
    public function handle(NotFoundLog $log): int
    {
        return $log->clear();
    }
}
