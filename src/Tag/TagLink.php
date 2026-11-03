<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Database\Entity;

/**
 * A tag of a published object (decision 0233).
 */
final readonly class TagLink extends Entity
{
    public function __construct(
        public int $id,
        public string $tagId,
        public string $targetType,
        public string $targetId,
    ) {}
}
