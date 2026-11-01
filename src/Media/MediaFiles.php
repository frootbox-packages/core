<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\ProjectPaths;

/**
 * Where the files of media lie (decision 0075): originals in
 * storage/media/, generated sizes in public/media/<id>/.
 */
final readonly class MediaFiles
{
    public function __construct(private ProjectPaths $paths) {}

    public function original(string $id): string
    {
        return $this->paths->storage('media/' . $id);
    }

    /**
     * @param string $path Below /media/, e.g. "01J…/1/kaffee-640x0-….webp"
     */
    public function public(string $path): string
    {
        return $this->paths->public('media/' . ltrim($path, '/'));
    }

    /**
     * Writes a generated file so the web server finds it next time.
     */
    public function writePublic(string $path, string $contents): void
    {
        $file = $this->public($path);
        $folder = \dirname($file);

        if (!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) {
            return;
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4));

        if (false !== @file_put_contents($temporary, $contents)) {
            @rename($temporary, $file);
        }
    }

    /**
     * Removes all generated files of a medium.
     */
    public function removePublic(string $id): void
    {
        self::removeTree($this->public($id));
    }

    /**
     * Originals that no medium owns any more and that are older than the
     * given number of seconds.
     *
     * @param callable(string): bool $owned Whether a medium has this id
     *
     * @return int How many were removed
     */
    public function pruneOriginals(callable $owned, int $age, ?int $now = null): int
    {
        $now ??= time();
        $removed = 0;

        $files = glob($this->paths->storage('media/*'));

        foreach (false === $files ? [] : $files as $file) {
            if (is_file($file) && filemtime($file) < $now - $age && !$owned(basename($file))) {
                @unlink($file);
                $this->removePublic(basename($file));
                ++$removed;
            }
        }

        return $removed;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}
