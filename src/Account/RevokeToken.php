<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\User\UserException;

/**
 * Ends one of the own sessions or API tokens.
 *
 * @implements Action<void>
 */
#[AsAction('account.revoke-token', 'Ends one of the own sessions or API tokens', signedIn: true, undoable: false)]
final readonly class RevokeToken implements Action
{
    public function __construct(public int $id) {}

    public function handle(AccessTokens $tokens, CurrentUser $current): void
    {
        $token = $tokens->find($this->id);

        if (null === $token || $token->userId !== $current->id()) {
            throw UserException::tokenNotFound($this->id);
        }

        $tokens->revoke($token->id);
    }
}
