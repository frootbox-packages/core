<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\Descriptor;

/**
 * Decides whether an actor may run an action or query: public ones
 * anyone, signed-in ones every user, all others need their permission,
 * everywhere or, for Scoped ones, within the scopes of their targets
 * (decision 0118).
 */
final readonly class Gate
{
    public function __construct(
        private Permissions $permissions,
        private ScopeResolvers $resolvers,
    ) {}

    /**
     * Whether the actor may run it at all: everywhere, or somewhere if it
     * is Scoped. Enough to show a control or list it in the API; the
     * targets are checked with allowsFor().
     */
    public function allows(Actor $actor, Descriptor $descriptor): bool
    {
        return match (true) {
            $descriptor->public => true,
            $descriptor->signedIn => ActorType::Guest !== $actor->type,
            $this->permissions->allows($actor, $descriptor->permission) => true,
            default => self::isScoped($descriptor) && [] !== $this->permissions->scopes($actor, $descriptor->permission),
        };
    }

    /**
     * Whether the actor may run it with this input, i.e. on its targets.
     */
    public function allowsFor(Actor $actor, Descriptor $descriptor, object $input): bool
    {
        return $this->allowsOn($actor, $descriptor, $input instanceof Scoped ? $input->scopes() : null);
    }

    /**
     * Whether the actor may run it on these targets; null: only with the
     * permission everywhere, see Scoped::scopes().
     *
     * @param list<Scope>|null $targets
     */
    public function allowsOn(Actor $actor, Descriptor $descriptor, ?array $targets): bool
    {
        if ($descriptor->public || $descriptor->signedIn || $this->permissions->allows($actor, $descriptor->permission)) {
            return $this->allows($actor, $descriptor);
        }

        if (null === $targets || !self::isScoped($descriptor)) {
            return false;
        }

        $scopes = $this->permissions->scopes($actor, $descriptor->permission);

        if ([] === $targets) {
            return [] !== $scopes;
        }

        foreach ($targets as $target) {
            if (!array_any($scopes, fn(Scope $scope): bool => $this->resolvers->covers($scope, $target))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The ids of the given kind where the actor holds the permission, for
     * queries that show only what lies within; null for everywhere.
     *
     * @return list<string>|null
     */
    public function within(Actor $actor, string $permission, string $kind): ?array
    {
        if ($this->permissions->allows($actor, $permission)) {
            return null;
        }

        $ids = [];

        foreach ($this->permissions->scopes($actor, $permission) as $scope) {
            if ($scope->kind === $kind) {
                $ids[] = $scope->id;
            }
        }

        return $ids;
    }

    private static function isScoped(Descriptor $descriptor): bool
    {
        return is_subclass_of($descriptor->class, Scoped::class);
    }
}
