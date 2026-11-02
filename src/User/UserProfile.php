<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

/**
 * A user with their roles.
 */
final readonly class UserProfile
{
    /**
     * @param list<array{role: string, scope: string|null}> $roles
     */
    public function __construct(
        public User $user,
        public array $roles,
    ) {}
}
