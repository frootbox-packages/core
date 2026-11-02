<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * A new token with its secret, which cannot be read again later.
 */
final readonly class IssuedToken
{
    public function __construct(
        public string $token,
        public AccessToken $accessToken,
    ) {}
}
