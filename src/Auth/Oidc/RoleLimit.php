<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\User\Roles;

/**
 * The role a provider gives, capped by the highest role the website
 * allows (decision 0322): min(role from the provider, maxRole), by the
 * levels of the roles. Only the standard roles count.
 */
final readonly class RoleLimit
{
    public const array STANDARD = ['editor', 'admin', 'superuser'];

    public function __construct(private Roles $roles) {}

    public function cap(?string $role, ?string $max): ?string
    {
        if (null === $role || !\in_array($role, self::STANDARD, true) || null === $this->roles->findByName($role)) {
            return null;
        }

        if (null === $max || !\in_array($max, self::STANDARD, true)) {
            return $role;
        }

        return $this->level($role) <= $this->level($max) ? $role : $max;
    }

    public function level(?string $role): int
    {
        return null === $role ? 0 : $this->roles->findByName($role)->level ?? 0;
    }
}
