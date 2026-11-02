<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Support\Message;
use Frootbox\Core\User\Role;
use Frootbox\Core\User\RoleAssignments;

/**
 * The ranking of users by the levels of their roles, e.g. superuser 100,
 * admin 50, editor 10. The level of a user is the highest of their roles.
 *
 * - Users are managed up to the own level: an admin edits other admins,
 *   but not a superuser.
 * - Roles are given up to the own level, but created, changed and deleted
 *   only below it, and never with permissions one does not have.
 * - Actions are undone only if they were run by someone up to the own
 *   level.
 *
 * The system stands above everyone.
 */
final readonly class Hierarchy
{
    public const int TOP = \PHP_INT_MAX;

    public function __construct(
        private CurrentContext $context,
        private RoleAssignments $assignments,
        private Permissions $permissions,
        private Catalog $catalog,
    ) {}

    public function levelOf(Actor $actor): int
    {
        return match (true) {
            ActorType::System === $actor->type => self::TOP,
            ActorType::User === $actor->type && null !== $actor->id => $this->assignments->globalLevel($actor->id),
            default => 0,
        };
    }

    public function assertMayManageUser(int $userId): void
    {
        if ($this->level() < $this->assignments->globalLevel($userId)) {
            throw new AccessDeniedException(new Message('User #{id} ranks above you.', ['id' => $userId]));
        }
    }

    public function assertMayAssign(Role $role): void
    {
        if ($this->level() < $role->level) {
            throw new AccessDeniedException(new Message('The role "{role}" ranks above you.', ['role' => $role->name]));
        }
    }

    /**
     * Roles are created, changed or deleted only below the own level.
     */
    public function assertMayChangeRole(string $name, int $level): void
    {
        if ($this->level() <= $level) {
            throw new AccessDeniedException(new Message('The role "{role}" does not rank below you.', ['role' => $name]));
        }
    }

    /**
     * A role may only grant permissions the actor has. Checked against all
     * actions and queries known right now.
     *
     * @param list<string> $rules
     */
    public function assertGrantable(string $action, array $rules): void
    {
        $actor = $this->context->get()->actor;
        $granted = new PermissionRules($rules);
        $missing = [];

        foreach ($this->catalog->all() as $descriptor) {
            if (!$descriptor->public && !$descriptor->signedIn && $granted->allows($descriptor->permission) && !$this->permissions->allows($actor, $descriptor->permission)) {
                $missing[$descriptor->permission] = $descriptor->permission;
            }
        }

        if ([] !== $missing) {
            throw new InvalidInputException($action, ['/permissions' => new Message('grants permissions you do not have: {permissions}', ['permissions' => implode(', ', $missing)])]);
        }
    }

    public function mayUndoActionsOf(string $actorType, ?int $actorId): bool
    {
        $original = match ($actorType) {
            ActorType::System->value => self::TOP,
            ActorType::User->value => null === $actorId ? 0 : $this->assignments->globalLevel($actorId),
            default => 0,
        };

        return $this->level() >= $original;
    }

    private function level(): int
    {
        return $this->levelOf($this->context->get()->actor);
    }
}
