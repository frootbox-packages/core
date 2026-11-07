<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\AccessTokens;

/**
 * The back channel of a provider after OIDC Back-Channel Logout 1.0
 * (decision 0322): with sid the sessions of that sign-in end, with sub
 * alone all sessions of the person through the provider. Sessions with
 * a password stay. Each logout token counts once.
 *
 * @implements Action<int>
 */
#[AsAction('account.oidc-logout', 'Ends sessions through a provider on its logout token and returns how many', public: true, undoable: false)]
final readonly class BackChannelLogout implements Action
{
    public function __construct(
        #[Length(min: 1, max: 50)]
        public string $provider,
        #[Sensitive]
        #[Length(min: 1, max: 8000)]
        public string $logoutToken,
    ) {}

    public function handle(Providers $providers, OidcClient $client, SeenTokens $seen, UserIdentities $identities, AccessTokens $tokens): int
    {
        $provider = $providers->get($this->provider);
        $claims = $client->logout($provider, $this->logoutToken);

        $jti = \is_string($claims['jti'] ?? null) ? $claims['jti'] : throw OidcException::failed('no jti');

        if (!$seen->first($provider->issuer . ' ' . $jti)) {
            throw OidcException::failed('this logout token came already');
        }

        $userId = null;

        if (\is_string($claims['sub'] ?? null)) {
            $identity = $identities->findBySubject($provider->issuer, $claims['sub']);

            if (null === $identity) {
                return 0;
            }

            $userId = $identity->userId;
        }

        return $tokens->endProviderSessions($provider->key, $userId, \is_string($claims['sid'] ?? null) ? $claims['sid'] : null);
    }
}
