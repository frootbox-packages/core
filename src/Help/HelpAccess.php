<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Auth\Permissions;

/**
 * Who may read a help page: everyone signed in, unless it names an
 * action or query the reader must be allowed (decision 0215). Pages
 * about what one may not do are hidden, not locked.
 */
final readonly class HelpAccess
{
    public function __construct(
        private Catalog $catalog,
        private Gate $gate,
        private Permissions $permissions,
    ) {}

    public function allows(Actor $actor, HelpTopic $topic): bool
    {
        $permission = $topic->permission;

        return null === $permission
            || ($this->catalog->has($permission)
                ? $this->gate->allows($actor, $this->catalog->get($permission))
                : $this->permissions->allows($actor, $permission));
    }
}
