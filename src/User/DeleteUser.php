<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\Auth\Hierarchy;

/**
 * Deletes a user with their roles and signs them out. Undoing restores
 * the user and the roles, but without a password.
 *
 * @implements Action<bool>
 */
#[AsAction('user.delete', 'Deletes a user')]
final readonly class DeleteUser implements Action
{
    public function __construct(public int $id) {}

    public function handle(Users $users, RoleAssignments $assignments, AccessTokens $tokens, CurrentUser $current, Hierarchy $hierarchy): bool
    {
        if ($current->is($this->id)) {
            throw UserException::notYourself('delete');
        }

        $hierarchy->assertMayManageUser($this->id);

        foreach ($assignments->fetch(['where' => ['userId' => $this->id]]) as $assignment) {
            $assignments->delete($assignment->id);
        }

        $tokens->revokeAll($this->id);

        return $users->delete($this->id);
    }
}
