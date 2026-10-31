<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\User\RoleAssignments;

/**
 * Permissions from roles: a user may do what one of their roles allows.
 * The system may do everything, a guest nothing beyond public actions.
 *
 * Roles given only for a scope (a branch of the page tree) do not count
 * here yet. They are checked once there are pages.
 */
final readonly class RolePermissions implements Permissions
{
    public function __construct(private RoleAssignments $assignments) {}

    public function allows(Actor $actor, string $permission): bool
    {
        if (ActorType::System === $actor->type) {
            return true;
        }

        if (ActorType::User !== $actor->type || null === $actor->id) {
            return false;
        }

        foreach ($this->assignments->globalRules($actor->id) as $rules) {
            if (new PermissionRules($rules)->allows($permission)) {
                return true;
            }
        }

        return false;
    }
}
