<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<UserProfile>
 */
#[AsQuery('user.show', 'Returns a user with their roles')]
final readonly class ShowUser implements Query
{
    public function __construct(public int $id) {}

    public function handle(Users $users, RoleAssignments $assignments): UserProfile
    {
        return new UserProfile($users->getById($this->id), $assignments->rolesOf($this->id));
    }
}
