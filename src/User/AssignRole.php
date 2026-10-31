<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Hierarchy;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\ScopeResolvers;
use Frootbox\Core\Support\Message;

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
        #[Description('Only within this scope, e.g. "page:01J…" for a page and everything below it')]
        public ?string $scope = null,
    ) {}

    public function handle(Users $users, Roles $roles, RoleAssignments $assignments, Hierarchy $hierarchy, ScopeResolvers $resolvers): RoleAssignment
    {
        $scope = null === $this->scope ? null : Scope::fromString($this->scope);

        if (null !== $this->scope && (null === $scope || !$resolvers->has($scope->kind))) {
            throw new InvalidInputException('user.assign-role', ['/scope' => new Message('There is no kind of scope "{scope}".', ['scope' => $this->scope])]);
        }

        if (null !== $scope && !$resolvers->exists($scope)) {
            throw new InvalidInputException('user.assign-role', ['/scope' => new Message('There is nothing for the scope "{scope}".', ['scope' => $this->scope])]);
        }

        $user = $users->getById($this->userId);
        $role = $roles->getByName($this->role);
        $hierarchy->assertMayManageUser($user->id);
        $hierarchy->assertMayAssign($role);

        return $assignments->find($user->id, $role->id, $this->scope)
            ?? $assignments->insert(['userId' => $user->id, 'roleId' => $role->id, 'scope' => $this->scope]);
    }
}
