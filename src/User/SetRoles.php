<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\ListOf;

/**
 * Sets the roles a user has everywhere in one go, e.g. from the
 * checkboxes of a form (decision 0115). Roles within a scope such as a
 * branch of the page tree stay as they are.
 *
 * @implements Action<UserProfile>
 */
#[AsAction('user.set-roles', 'Sets the roles a user has everywhere, roles within a scope stay')]
final readonly class SetRoles implements Action
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public int $userId,
        #[ListOf('string')]
        #[Description('Role names, e.g. ["editor"]; an empty list takes all away')]
        public array $roles,
    ) {}

    public function handle(Users $users, RoleAssignments $assignments, Runner $runner): UserProfile
    {
        $user = $users->getById($this->userId);
        $current = array_column(array_filter($assignments->rolesOf($user->id), static fn(array $assignment): bool => null === $assignment['scope']), 'role');
        $wanted = array_values(array_unique($this->roles));

        // Each change is its own action, with the checks of the hierarchy.
        foreach (array_diff($current, $wanted) as $role) {
            $runner->run(new RemoveRole($user->id, $role));
        }

        foreach (array_diff($wanted, $current) as $role) {
            $runner->run(new AssignRole($user->id, $role));
        }

        return new UserProfile($user, $assignments->rolesOf($user->id));
    }
}
