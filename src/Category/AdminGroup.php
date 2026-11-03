<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A group of categories an admin created (decision 0233).
 */
final readonly class AdminGroup extends Entity
{
    /**
     * @param list<string> $types
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $types,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
