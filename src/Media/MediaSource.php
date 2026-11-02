<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A further file of a video in another format or codec (decision 0177).
 * The file lies in storage/media/ under the name in $file.
 */
final readonly class MediaSource extends Entity
{
    public function __construct(
        public string $id,
        public string $mediaId,
        public string $file,
        public string $type,
        public string $codec,
        public int $size,
        public int $width,
        public int $height,
        public ?string $hash,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
