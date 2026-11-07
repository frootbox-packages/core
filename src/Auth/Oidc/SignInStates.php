<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Security\Secrets;

/**
 * Makes and reads the state of a sign-in, encrypted with Secrets for the
 * purpose "oidc.state" and valid 10 minutes (decision 0322).
 */
final readonly class SignInStates
{
    public const string PURPOSE = 'oidc.state';

    public const int MINUTES = 10;

    public function __construct(private Secrets $secrets) {}

    public function create(string $provider, string $redirectUri, ?string $next = null, ?int $linkUserId = null, bool $silent = false): SignInState
    {
        return new SignInState($provider, SignInState::random(), SignInState::random(), SignInState::random(48), $redirectUri, $next, $linkUserId, $silent, time() + self::MINUTES * 60);
    }

    public function encode(SignInState $state): string
    {
        return $this->secrets->encrypt(self::PURPOSE, json_encode([
            'p' => $state->provider,
            's' => $state->state,
            'n' => $state->nonce,
            'v' => $state->verifier,
            'r' => $state->redirectUri,
            'x' => $state->next,
            'l' => $state->linkUserId,
            'q' => $state->silent,
            'e' => $state->expiresAt,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * The state for a provider and the state the provider sent back.
     *
     * @throws OidcException if it is broken, expired or belongs elsewhere
     */
    public function decode(string $stored, string $provider, string $state): SignInState
    {
        $json = $this->secrets->decrypt(self::PURPOSE, $stored);
        $data = null === $json ? null : json_decode($json, true);

        if (!\is_array($data) || !\is_string($data['p'] ?? null) || !\is_string($data['s'] ?? null) || !\is_string($data['n'] ?? null) || !\is_string($data['v'] ?? null) || !\is_string($data['r'] ?? null) || !\is_int($data['e'] ?? null)) {
            throw OidcException::badState();
        }

        if ($data['p'] !== $provider || !hash_equals($data['s'], $state) || $data['e'] < time()) {
            throw OidcException::badState();
        }

        return new SignInState(
            $data['p'],
            $data['s'],
            $data['n'],
            $data['v'],
            $data['r'],
            \is_string($data['x'] ?? null) ? $data['x'] : null,
            \is_int($data['l'] ?? null) ? $data['l'] : null,
            true === ($data['q'] ?? null),
            $data['e'],
        );
    }
}
