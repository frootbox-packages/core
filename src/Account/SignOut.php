<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\CurrentUser;

/**
 * Ends the current session or invalidates the API token in use.
 *
 * @implements Action<bool>
 */
#[AsAction('account.sign-out', 'Ends the current session', signedIn: true, undoable: false)]
final readonly class SignOut implements Action
{
    public function handle(AccessTokens $tokens, CurrentUser $current): bool
    {
        $current->id();
        $tokenId = $current->tokenId();

        return null !== $tokenId && $tokens->revoke($tokenId);
    }
}
