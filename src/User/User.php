<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A person who signs in. The password hash stays in the table and never
 * leaves it through the entity.
 */
final readonly class User extends Entity
{
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public bool $active,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        /** The language of the backend, null for automatic (decision 0161) */
        public ?string $language = null,
    ) {}
}
