<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A space of a plugin besides the default one (decision 0232), e.g. the
 * jobs "Ausbildung" next to all other jobs. Every object belongs to one.
 * The default space of each type has no row: its id is "default".
 */
final readonly class Space extends Entity
{
    public const string DEFAULT = 'default';

    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
