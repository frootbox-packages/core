<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Hierarchy;

/**
 * @implements Action<bool>
 */
#[AsAction('user.remove-role', 'Takes a role away from a user')]
final readonly class RemoveRole implements Action
{
    public function __construct(
        public int $userId,
        public string $role,
        #[Pattern(AssignRole::SCOPE_PATTERN)]
        #[Length(max: 100)]
        public ?string $scope = null,
    ) {}

    public function handle(Roles $roles, RoleAssignments $assignments, Hierarchy $hierarchy): bool
    {
        $role = $roles->getByName($this->role);
        $hierarchy->assertMayManageUser($this->userId);
        $hierarchy->assertMayAssign($role);
        $assignment = $assignments->find($this->userId, $role->id, $this->scope);

        return null !== $assignment && $assignments->delete($assignment->id);
    }
}
