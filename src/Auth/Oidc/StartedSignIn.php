<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

/**
 * The start of a sign-in: where the browser goes, and the state the
 * backend keeps in a cookie until it comes back (decision 0322).
 */
final readonly class StartedSignIn
{
    public function __construct(
        public string $provider,
        public string $url,
        public string $stored,
        public int $minutes = SignInStates::MINUTES,
    ) {}
}
