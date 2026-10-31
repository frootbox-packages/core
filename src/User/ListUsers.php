<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Database\Result;

/**
 * @implements Query<Result<User>>
 */
#[AsQuery('user.list', 'Lists all users by name')]
final readonly class ListUsers implements Query
{
    /**
     * @return Result<User>
     */
    public function handle(Users $users): Result
    {
        return $users->fetch(['order' => ['name', 'id']]);
    }
}
