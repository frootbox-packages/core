<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A manual redirect from a path to a target or a free URL (decision 0105).
 */
final readonly class Redirect extends Entity
{
    public function __construct(
        public int $id,
        public string $path,
        public ?string $target,
        public ?string $location,
        public bool $permanent,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
