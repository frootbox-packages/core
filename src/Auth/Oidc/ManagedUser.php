<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

/**
 * A user whose role and lock come from a provider such as the hub
 * (decision 0322), for the badge in the list of users.
 */
final readonly class ManagedUser
{
    public function __construct(
        public int $userId,
        public string $provider,
        public string $title,
    ) {}
}
