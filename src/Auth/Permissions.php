<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * Decides whether an actor holds a permission, e.g. "page.create",
 * everywhere or only within scopes (decision 0118).
 */
interface Permissions
{
    /**
     * Whether the actor holds the permission everywhere.
     */
    public function allows(Actor $actor, string $permission): bool;

    /**
     * Where the actor holds the permission through roles given only
     * within a scope, e.g. a branch of the page tree.
     *
     * @return list<Scope>
     */
    public function scopes(Actor $actor, string $permission): array;
}
