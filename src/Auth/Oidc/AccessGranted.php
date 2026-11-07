<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\User\User;

/**
 * What user.grant-access did: "created", "linked" or "updated".
 */
final readonly class AccessGranted
{
    public function __construct(
        public string $result,
        public User $user,
    ) {}
}
