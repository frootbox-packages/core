<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A path that found nothing, with how often and when (decision 0105).
 */
final readonly class NotFoundEntry extends Entity
{
    public function __construct(
        public int $id,
        public string $path,
        public int $hits,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
    ) {}
}
