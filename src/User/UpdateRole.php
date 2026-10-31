<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Hierarchy;

/**
 * @implements Action<Role>
 */
#[AsAction('role.update', 'Changes the title, permissions and level of a role')]
final readonly class UpdateRole implements Action
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public string $name,
        #[Length(min: 1, max: 255)]
        public string $title,
        #[ListOf('string')]
        public array $permissions,
        #[Range(min: 0, max: 1000)]
        public int $level,
    ) {}

    public function handle(Roles $roles, Hierarchy $hierarchy): Role
    {
        $role = $roles->getByName($this->name);
        $hierarchy->assertMayChangeRole($role->name, $role->level);
        $hierarchy->assertMayChangeRole($role->name, $this->level);
        RoleRules::check('role.update', $this->permissions);
        $hierarchy->assertGrantable('role.update', $this->permissions);

        return $roles->update($role->id, ['title' => $this->title, 'permissions' => $this->permissions, 'level' => $this->level]);
    }
}
