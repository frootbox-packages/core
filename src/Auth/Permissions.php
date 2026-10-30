<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * Decides whether an actor holds a permission, e.g. "page.create".
 */
interface Permissions
{
    public function allows(Actor $actor, string $permission): bool;
}
