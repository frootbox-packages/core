<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * Knows one kind of scope, e.g. "page" for branches of the page tree
 * (decision 0118). Registered with $registry->scope().
 */
interface ScopeResolver
{
    /**
     * Whether a role given within $scope applies to $target. Strict
     * targets must lie below $scope, not be $scope itself.
     */
    public function covers(string $scope, string $target, bool $strict): bool;

    /**
     * Whether there is something with this id to give a role for.
     */
    public function exists(string $id): bool;
}
