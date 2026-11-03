<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\ProjectPaths;
use InvalidArgumentException;

/**
 * Font files of extensions (decision 0252): kept in
 * storage/fonts/<vendor>/<package>/ and copied to
 * public/media/fonts/<vendor>/<package>/ when first shown, like the
 * copies of published media, so they come back after a restore.
 */
final readonly class StoredFonts
{
    public const string FOLDER = 'fonts';

    /** Below public/, next to the copies of media. */
    public const string PUBLIC = 'media/fonts';

    private const string NAME = '/^[a-z0-9][a-z0-9._-]*\.(woff2|woff|ttf|otf)$/';

    public function __construct(private ProjectPaths $paths) {}

    /**
     * Keeps a file and gives its path in storage/.
     */
    public function save(string $package, string $name, string $content): string
    {
        $file = $this->path($package, $name);
        @mkdir(\dirname($file), 0o775, true);
        file_put_contents($file, $content);
        @unlink($this->copy($package, $name));

        return $file;
    }

    /**
     * Removes a file and its copy.
     */
    public function remove(string $package, string $name): void
    {
        @unlink($this->path($package, $name));
        @unlink($this->copy($package, $name));
    }

    /**
     * The path of a file in storage/.
     */
    public function path(string $package, string $name): string
    {
        return $this->paths->storage(self::FOLDER . '/' . self::package($package) . '/' . self::name($name));
    }

    /**
     * The address of a file, copied to public/ if needed, with the time of
     * its last change, e.g. "/media/fonts/frootbox/google-fonts/inter-latin.woff2?v=1761900000".
     */
    public function url(string $package, string $name): string
    {
        $file = $this->path($package, $name);
        $copy = $this->copy($package, $name);

        if (is_file($file) && (!is_file($copy) || filemtime($copy) < filemtime($file))) {
            @mkdir(\dirname($copy), 0o775, true);
            copy($file, $copy);
        }

        return '/' . self::PUBLIC . '/' . self::package($package) . '/' . self::name($name) . '?v=' . (is_file($file) ? (int) filemtime($file) : 0);
    }

    private function copy(string $package, string $name): string
    {
        return $this->paths->public(self::PUBLIC . '/' . self::package($package) . '/' . self::name($name));
    }

    private static function package(string $package): string
    {
        return 1 === preg_match('#^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$#', $package) ? $package : throw new InvalidArgumentException(\sprintf('"%s" is no package name.', $package));
    }

    private static function name(string $name): string
    {
        return 1 === preg_match(self::NAME, $name) ? $name : throw new InvalidArgumentException(\sprintf('"%s" is no name of a font file.', $name));
    }
}
