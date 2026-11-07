<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

/**
 * The link of an account to a provider as the backend shows it.
 */
final readonly class IdentityInfo
{
    public function __construct(
        public string $provider,
        public string $title,
        public bool $managed,
        public string $createdAt,
        public ?string $lastUsedAt,
    ) {}

    /**
     * @return list<self>
     */
    public static function of(UserIdentities $identities, Providers $providers, int $userId): array
    {
        $titles = [];

        foreach ($providers->all() as $provider) {
            $titles[$provider->key] = $provider->title;
        }

        return array_map(static fn(UserIdentity $identity): self => new self(
            $identity->provider,
            $titles[$identity->provider] ?? $identity->provider,
            $identity->managed,
            $identity->createdAt->format(\DATE_ATOM),
            $identity->lastUsedAt?->format(\DATE_ATOM),
        ), $identities->forUser($userId));
    }
}
