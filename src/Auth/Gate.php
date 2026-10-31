<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\Descriptor;

/**
 * Decides whether an actor may run an action or query: public ones
 * anyone, signed-in ones every user, all others need their permission.
 */
final readonly class Gate
{
    public function __construct(private Permissions $permissions) {}

    public function allows(Actor $actor, Descriptor $descriptor): bool
    {
        return match (true) {
            $descriptor->public => true,
            $descriptor->signedIn => ActorType::Guest !== $actor->type,
            default => $this->permissions->allows($actor, $descriptor->permission),
        };
    }
}
