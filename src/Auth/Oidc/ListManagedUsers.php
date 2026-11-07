<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * Which users a provider manages, for all users at once (decision 0322).
 *
 * @implements Query<list<ManagedUser>>
 */
#[AsQuery('user.managed-list', 'Lists the users whose role and lock come from a provider such as the hub', permission: 'user.list')]
final readonly class ListManagedUsers implements Query
{
    /**
     * @return list<ManagedUser>
     */
    public function handle(UserIdentities $identities, Providers $providers): array
    {
        $titles = [];

        foreach ($providers->all() as $provider) {
            $titles[$provider->key] = $provider->title;
        }

        return array_map(static fn(UserIdentity $identity): ManagedUser => new ManagedUser(
            $identity->userId,
            $identity->provider,
            $titles[$identity->provider] ?? $identity->provider,
        ), $identities->managed());
    }
}
