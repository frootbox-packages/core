<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Auth\Hierarchy;

/**
 * Deletes a role and takes it away from all users. The standard roles
 * superuser and admin stay.
 *
 * @implements Action<bool>
 */
#[AsAction('role.delete', 'Deletes a role and takes it away from all users')]
final readonly class DeleteRole implements Action
{
    public const array PROTECTED = ['superuser', 'admin'];

    public function __construct(public string $name) {}

    public function handle(Roles $roles, RoleAssignments $assignments, Hierarchy $hierarchy): bool
    {
        if (\in_array($this->name, self::PROTECTED, true)) {
            throw UserException::protectedRole($this->name);
        }

        $role = $roles->getByName($this->name);
        $hierarchy->assertMayChangeRole($role->name, $role->level);

        foreach ($assignments->fetch(['where' => ['roleId' => $role->id]]) as $assignment) {
            $assignments->delete($assignment->id);
        }

        return $roles->delete($role->id);
    }
}
