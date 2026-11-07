<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

/**
 * What the browser carries from the start of a sign-in to its return
 * (decision 0322): state, nonce and the PKCE verifier, encrypted in a
 * cookie, so nothing is written before the sign-in. With linkUserId the
 * return links the signed-in account instead of signing in.
 */
final readonly class SignInState
{
    public function __construct(
        public string $provider,
        public string $state,
        public string $nonce,
        public string $verifier,
        public string $redirectUri,
        public ?string $next,
        public ?int $linkUserId,
        public bool $silent,
        public int $expiresAt,
    ) {}

    /**
     * The PKCE challenge for S256.
     */
    public function challenge(): string
    {
        return self::base64url(hash('sha256', $this->verifier, true));
    }

    /**
     * @param int<1, max> $bytes
     */
    public static function random(int $bytes = 32): string
    {
        return self::base64url(random_bytes($bytes));
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
