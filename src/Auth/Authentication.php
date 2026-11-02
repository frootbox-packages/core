<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * Who a valid token belongs to.
 */
final readonly class Authentication
{
    public function __construct(
        public Actor $actor,
        public AccessToken $token,
    ) {}
}
