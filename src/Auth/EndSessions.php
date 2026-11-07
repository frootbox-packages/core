<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Visitor\VisitorSessions;

/**
 * Signs everyone out and forgets the sessions of visitors (decision 0319).
 * A session belongs to the installation it began in, so a copy of the
 * website, e.g. from "workbench build --with-content", starts without.
 * API tokens stay: they are given out on purpose.
 *
 * @implements Action<int>
 */
#[AsAction('system.end-sessions', 'Ends the sessions of all users and visitors and returns how many; API tokens stay', undoable: false)]
final readonly class EndSessions implements Action
{
    public function handle(AccessTokens $tokens, VisitorSessions $visitors): int
    {
        return $tokens->endSessions() + $visitors->clear();
    }
}
