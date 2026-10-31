<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Hierarchy;

/**
 * @implements Action<RoleAssignment>
 */
#[AsAction('user.assign-role', 'Gives a user a role, everywhere or only within a scope')]
final readonly class AssignRole implements Action
{
    public const string SCOPE_PATTERN = '^[a-z][a-z0-9-]*:[A-Za-z0-9_-]+$';

    public function __construct(
        public int $userId,
        #[Description('Name of the role, e.g. "editor"')]
        public string $role,
        #[Pattern(self::SCOPE_PATTERN)]
        #[Length(max: 100)]
        #[Description('Only within this scope, e.g. "page:12" for a branch of the page tree')]
        public ?string $scope = null,
    ) {}

    public function handle(Users $users, Roles $roles, RoleAssignments $assignments, Hierarchy $hierarchy): RoleAssignment
    {
        $user = $users->getById($this->userId);
        $role = $roles->getByName($this->role);
        $hierarchy->assertMayManageUser($user->id);
        $hierarchy->assertMayAssign($role);

        return $assignments->find($user->id, $role->id, $this->scope)
            ?? $assignments->insert(['userId' => $user->id, 'roleId' => $role->id, 'scope' => $this->scope]);
    }
}
