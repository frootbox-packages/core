<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\CurrentUser;

/**
 * The links of the own account to providers.
 *
 * @implements Query<list<IdentityInfo>>
 */
#[AsQuery('account.oidc-identities', 'Lists the links of the own account to providers such as the hub', signedIn: true)]
final readonly class ListIdentities implements Query
{
    /**
     * @return list<IdentityInfo>
     */
    public function handle(UserIdentities $identities, Providers $providers, CurrentUser $current): array
    {
        return IdentityInfo::of($identities, $providers, $current->id());
    }
}
