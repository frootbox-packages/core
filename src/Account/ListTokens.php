<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\AccessToken;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\CurrentUser;

/**
 * @implements Query<list<AccessToken>>
 */
#[AsQuery('account.tokens', 'Lists the own sessions and API tokens', signedIn: true)]
final readonly class ListTokens implements Query
{
    /**
     * @return list<AccessToken>
     */
    public function handle(AccessTokens $tokens, CurrentUser $current): array
    {
        return $tokens->forUser($current->id());
    }
}
