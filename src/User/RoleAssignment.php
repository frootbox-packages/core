<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A role given to a user, everywhere or only within a scope such as
 * "page:12" (a branch of the page tree).
 */
final readonly class RoleAssignment extends Entity
{
    public function __construct(
        public int $id,
        public int $userId,
        public int $roleId,
        public ?string $scope,
        public DateTimeImmutable $createdAt,
    ) {}
}
