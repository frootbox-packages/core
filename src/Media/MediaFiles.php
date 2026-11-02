<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\ProjectPaths;

/**
 * Where the files of media lie (decision 0075): originals in
 * storage/media/, generated sizes in public/media/<id>/. The first
 * original of a medium is named by its id, each replacing one by the id
 * and a random part (decision 0147).
 */
final readonly class MediaFiles
{
    public function __construct(private ProjectPaths $paths) {}

    /**
     * @param string $name The id for the first file of a medium, see Medium::original()
     */
    public function original(string $name): string
    {
        return $this->paths->storage('media/' . $name);
    }

    /**
     * A name for another original of the medium.
     */
    public static function newName(string $id): string
    {
        return $id . '-' . bin2hex(random_bytes(4));
    }

    /**
     * An original no medium or draft uses any more stays for undo;
     * media:prune counts its age from now.
     */
    public function release(string $name): void
    {
        $file = $this->original($name);

        if (is_file($file)) {
            @touch($file);
        }
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
     * Copies a file, e.g. of a video, so the web server finds it next
     * time (decision 0177).
     */
    public function copyPublic(string $source, string $path): void
    {
        $file = $this->public($path);
        $folder = \dirname($file);

        if (is_file($file) || (!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder))) {
            return;
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4));

        if (@copy($source, $temporary)) {
            @rename($temporary, $file);
        } else {
            @unlink($temporary);
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
     * Originals that no medium and no draft uses any more and that are
     * older than the given number of seconds. The generated files go with
     * the original of a medium that is gone.
     *
     * @param callable(string): bool $used   Whether a medium or a draft uses the original of this name
     * @param callable(string): bool $exists Whether a medium has this id
     *
     * @return int How many were removed
     */
    public function pruneOriginals(callable $used, callable $exists, int $age, ?int $now = null): int
    {
        $now ??= time();
        $removed = 0;

        $files = glob($this->paths->storage('media/*'));

        foreach (false === $files ? [] : $files as $file) {
            $name = basename($file);

            if (is_file($file) && filemtime($file) < $now - $age && !$used($name)) {
                @unlink($file);
                $id = explode('-', $name, 2)[0];

                if (!$exists($id)) {
                    $this->removePublic($id);
                }

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
