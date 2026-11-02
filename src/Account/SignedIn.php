<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Auth\AccessToken;
use Frootbox\Core\User\User;

/**
 * The result of signing in: the session token, shown only this once.
 */
final readonly class SignedIn
{
    public function __construct(
        public string $token,
        public AccessToken $session,
        public User $user,
    ) {}
}
