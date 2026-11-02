<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\User\RoleAssignments;
use Frootbox\Core\User\UserProfile;
use Frootbox\Core\User\Users;

/**
 * @implements Query<UserProfile>
 */
#[AsQuery('account.show', 'Returns the signed-in user with their roles', signedIn: true)]
final readonly class ShowAccount implements Query
{
    public function handle(Users $users, RoleAssignments $assignments, CurrentUser $current): UserProfile
    {
        $id = $current->id();

        return new UserProfile($users->getById($id), $assignments->rolesOf($id));
    }
}
