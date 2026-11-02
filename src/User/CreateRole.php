<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Hierarchy;

/**
 * @implements Action<Role>
 */
#[AsAction('role.create', 'Creates a role that bundles permissions')]
final readonly class CreateRole implements Action
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        #[Pattern('^[a-z][a-z0-9-]*$')]
        #[Length(max: 100)]
        #[Description('Short name, e.g. "editor"')]
        public string $name,
        #[Length(min: 1, max: 255)]
        public string $title,
        #[ListOf('string')]
        #[Description('Rules such as "*", "page.*", "media.upload" or "-page.delete"')]
        public array $permissions = [],
        #[Range(min: 0, max: 1000)]
        #[Description('Rank: users manage users up to their own level and roles below it')]
        public int $level = 0,
    ) {}

    public function handle(Roles $roles, Hierarchy $hierarchy): Role
    {
        $hierarchy->assertMayChangeRole($this->name, $this->level);

        if (null !== $roles->findByName($this->name)) {
            throw new InvalidInputException('role.create', ['/name' => 'is already in use.']);
        }

        RoleRules::check('role.create', $this->permissions);
        $hierarchy->assertGrantable('role.create', $this->permissions);

        return $roles->insert(['name' => $this->name, 'title' => $this->title, 'level' => $this->level, 'permissions' => $this->permissions]);
    }
}
