<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Cache;

/**
 * A serialized value in var/cache that is valid as long as its
 * fingerprint matches. bin/frootbox cache:clear removes all of them.
 */
final class CacheFile
{
    /**
     * @param list<class-string> $classes Classes the value may contain
     */
    public static function read(string $file, string $fingerprint, array $classes): mixed
    {
        if (!is_file($file)) {
            return null;
        }

        [$cachedFingerprint, $serialized] = explode("\n", (string) file_get_contents($file), 2) + ['', ''];

        if ($cachedFingerprint !== $fingerprint) {
            return null;
        }

        $value = @unserialize($serialized, ['allowed_classes' => $classes]);

        return false === $value ? null : $value;
    }

    public static function write(string $file, string $fingerprint, mixed $value): void
    {
        $directory = \dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        // Write to a temporary file first, so no request reads a half-written cache.
        $temporary = $file . '.' . bin2hex(random_bytes(4));

        if (false !== @file_put_contents($temporary, $fingerprint . "\n" . serialize($value))) {
            rename($temporary, $file);
        }
    }
}
