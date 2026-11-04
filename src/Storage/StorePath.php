<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

/**
 * The rules for paths in a FileStore: relative, forward slashes, no
 * "." or ".." as a part, no control characters.
 */
final class StorePath
{
    /**
     * @throws StorageException For a path that would leave the store
     */
    public static function clean(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        foreach (explode('/', $path) as $part) {
            if ('.' === $part || '..' === $part || 1 === preg_match('/[\x00-\x1f\x7f]/', $part)) {
                throw new StorageException(\sprintf('The path "%s" is not allowed in a store.', $path));
            }
        }

        return $path;
    }
}
