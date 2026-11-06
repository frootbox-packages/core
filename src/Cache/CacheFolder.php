<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Cache;

use FilesystemIterator;
use Frootbox\Core\ProjectPaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * var/cache: pages for guests, compiled templates, the container and
 * the lists of extensions and blocks. Everything there is rebuilt on the
 * next request, so it may be emptied at any time.
 */
final readonly class CacheFolder
{
    public function __construct(private ProjectPaths $paths) {}

    /**
     * Empties the folder and gives the number of files removed.
     */
    public function clear(): int
    {
        $directory = $this->paths->var('cache');
        $removed = 0;

        if (!is_dir($directory)) {
            return 0;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            // Another request may be filling or emptying it at the same time.
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } elseif (@unlink($item->getPathname())) {
                ++$removed;
            }
        }

        return $removed;
    }
}
