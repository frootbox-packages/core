<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * The password of the website while the protection is on (decision 0153).
 */
final readonly class Protection extends Entity
{
    public function __construct(
        public int $id,
        public string $passwordHash,
        public string $generation,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
