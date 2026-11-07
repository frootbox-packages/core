<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * The links of a user to providers, e.g. "managed through frootbox.de".
 *
 * @implements Query<list<IdentityInfo>>
 */
#[AsQuery('user.identities', 'Lists the links of a user to providers such as the hub', permission: 'user.show')]
final readonly class ListUserIdentities implements Query
{
    public function __construct(public int $userId) {}

    /**
     * @return list<IdentityInfo>
     */
    public function handle(UserIdentities $identities, Providers $providers): array
    {
        return IdentityInfo::of($identities, $providers, $this->userId);
    }
}
