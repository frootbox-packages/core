<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * The published items of a navigation in one language.
 */
final readonly class Navigation extends Entity
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $language,
        public array $items,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
