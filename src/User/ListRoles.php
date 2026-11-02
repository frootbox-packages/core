<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Database\Result;

/**
 * @implements Query<Result<Role>>
 */
#[AsQuery('role.list', 'Lists all roles with their permissions')]
final readonly class ListRoles implements Query
{
    /**
     * @return Result<Role>
     */
    public function handle(Roles $roles): Result
    {
        return $roles->fetch(['order' => 'name']);
    }
}
