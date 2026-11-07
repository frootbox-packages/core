<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * The providers one can sign in with right now, for the sign-in page.
 *
 * @implements Query<list<ProviderInfo>>
 */
#[AsQuery('account.oidc-providers', 'Lists the providers of OpenID Connect one can sign in with, such as the hub', public: true)]
final readonly class ListProviders implements Query
{
    /**
     * @return list<ProviderInfo>
     */
    public function handle(Providers $providers): array
    {
        return array_map(static fn(Provider $provider): ProviderInfo => new ProviderInfo($provider->key, $provider->title, $provider->issuer), $providers->all());
    }
}
