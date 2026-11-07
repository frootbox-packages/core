<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

/**
 * A provider of OpenID Connect a person signs in with (decision 0322),
 * e.g. the hub. maxRole caps the role the provider may give; roleClaim is
 * the path of the role in the ID token, e.g. "hub_access.role".
 */
final readonly class Provider
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $issuer,
        public string $clientId,
        public ClientAuthentication $authentication,
        public ?string $maxRole = null,
        public ?string $roleClaim = null,
        public array $scopes = ['openid', 'email', 'profile'],
    ) {}

    /**
     * The role the claims name, null without one.
     *
     * @param array<mixed> $claims
     */
    public function role(array $claims): ?string
    {
        if (null === $this->roleClaim) {
            return null;
        }

        $value = $claims;

        foreach (explode('.', $this->roleClaim) as $part) {
            $value = \is_array($value) ? $value[$part] ?? null : null;
        }

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
