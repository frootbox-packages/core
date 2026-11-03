<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Auth\Gate;

/**
 * Where the current user holds a permission among the spaces of a type
 * (decision 0245), for lists that show only the objects of those spaces.
 */
final readonly class SpaceRights
{
    public function __construct(
        private Gate $gate,
        private CurrentContext $context,
    ) {}

    /**
     * The spaces of the type the permission holds for; null for all,
     * because the user holds it everywhere.
     *
     * @return list<string>|null
     */
    public function within(string $type, string $permission): ?array
    {
        $within = $this->gate->within($this->context->get()->actor, $permission, SpaceScopes::KIND);

        if (null === $within) {
            return null;
        }

        $spaces = [];

        foreach ($within as $id) {
            if (str_starts_with($id, $type . ':')) {
                $spaces[] = substr($id, \strlen($type) + 1);
            }
        }

        return $spaces;
    }

    /**
     * Whether the permission holds for a space of the type.
     */
    public function allows(string $type, string $space, string $permission): bool
    {
        $within = $this->within($type, $permission);

        return null === $within || \in_array($space, $within, true);
    }
}
