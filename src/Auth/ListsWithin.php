<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * A scope resolver that names everything within some scopes at once, so
 * a list such as the hits of the search (decision 0214) is filtered
 * without asking once per entry.
 */
interface ListsWithin extends ScopeResolver
{
    /**
     * The ids within these scopes, the scopes themselves included.
     *
     * @param list<string> $scopes
     *
     * @return list<string>
     */
    public function within(array $scopes): array;
}
