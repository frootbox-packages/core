<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * Until users and roles exist, only the system may run protected actions.
 * Public actions are open to everyone anyway.
 */
final class SystemPermissions implements Permissions
{
    public function allows(Actor $actor, string $permission): bool
    {
        return ActorType::System === $actor->type;
    }
}
