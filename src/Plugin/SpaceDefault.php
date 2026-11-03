<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * The name of the default space of a plugin (decision 0246), e.g.
 * "Referenzen" for the collections or "Team" for the people.
 */
final readonly class SpaceDefault extends Entity
{
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
