<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A folder of the media library, inside another one or at the top.
 */
final readonly class MediaFolder extends Entity
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $name,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
