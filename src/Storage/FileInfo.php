<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

/**
 * A file in a FileStore.
 */
final readonly class FileInfo
{
    /**
     * @param int $modified Unix time of the last change
     */
    public function __construct(
        public string $path,
        public int $size,
        public int $modified,
    ) {}
}
