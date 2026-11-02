<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

/**
 * A folder of the media library with the number of media in it.
 */
final readonly class FolderSummary
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $name,
        public int $media,
    ) {}
}
