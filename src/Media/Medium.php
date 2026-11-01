<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * An image of the media library (decision 0126). The original lies in
 * storage/media/ under the name in $file, the id for the first file
 * (decision 0147); the focus point is in thousandths of width and
 * height. The version grows with every change that alters the sizes.
 * The hash is the SHA-256 of the uploaded file, none for media from
 * before decision 0146.
 */
final readonly class Medium extends Entity
{
    public function __construct(
        public string $id,
        public ?string $folderId,
        public string $fileName,
        public string $type,
        public int $size,
        public int $width,
        public int $height,
        public int $focusX,
        public int $focusY,
        public int $version,
        public bool $public,
        public ?string $hash,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public ?string $file = null,
    ) {}

    /**
     * The name of the original below storage/media/.
     */
    public function original(): string
    {
        return $this->file ?? $this->id;
    }
}
