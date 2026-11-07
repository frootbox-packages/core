<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Auth\AccessToken;
use Frootbox\Core\User\User;

/**
 * The return from a provider (decision 0322): a session like
 * account.sign-in, or, after account.oidc-link, the linked account
 * without a new session.
 */
final readonly class CompletedSignIn
{
    public function __construct(
        public User $user,
        public bool $linked,
        public ?string $next,
        public ?string $token = null,
        public ?AccessToken $session = null,
    ) {}
}
