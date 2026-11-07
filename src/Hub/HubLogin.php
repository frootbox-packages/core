<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Auth\Oidc\PrivateKeyJwt;
use Frootbox\Core\Auth\Oidc\Provider;
use Frootbox\Core\Auth\Oidc\ProviderSource;

/**
 * The hub as the first provider of OpenID Connect (decision 0322), while
 * the website is connected and its switch "login" is on. Its client id
 * is the id of the connection, the website signs in with private_key_jwt
 * and the key of the connection, so there is no secret to keep. The role
 * comes from hub_access.role, capped by the highest role the website
 * chose.
 */
final readonly class HubLogin implements ProviderSource
{
    public const string PROVIDER = 'hub';

    public function __construct(
        private Hub $hub,
        private HubClient $client,
    ) {}

    public function providers(): array
    {
        $connection = $this->hub->current();

        if (null === $connection || !$connection->connected() || !$connection->login || null === $connection->connectionId) {
            return [];
        }

        return [new Provider(
            self::PROVIDER,
            Hub::name($connection->hubUrl),
            $connection->hubUrl,
            $connection->connectionId,
            new PrivateKeyJwt(fn(): string => $this->client->secretKey($connection)),
            $connection->loginMaxRole ?? SwitchHub::MAX_ROLE,
            'hub_access.role',
        )];
    }
}
