<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Auth\ScopeResolver;

/**
 * Single notes as scopes, as an extension registers its own kind.
 */
final readonly class NoteScopes implements ScopeResolver
{
    public function __construct(private Notes $notes) {}

    public function covers(string $scope, string $target, bool $strict): bool
    {
        return !$strict && $scope === $target;
    }

    public function exists(string $id): bool
    {
        return ctype_digit($id) && null !== $this->notes->fetchById((int) $id);
    }
}
