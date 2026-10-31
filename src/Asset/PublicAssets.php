<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Asset;

use FilesystemIterator;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\ProjectPaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Makes the assets/ folders of packages reachable under
 * /assets/<vendor>/<package>/ (decision 0103): in development as
 * symlinks, so changes show at once, live as copies.
 *
 * Only folders named "assets" are published, never a whole package, so
 * PHP files and configuration stay private.
 */
final readonly class PublicAssets
{
    public const string PATH = '/assets';

    public const string FOLDER = 'assets';

    private const string CORE = 'frootbox/core';

    public function __construct(
        private Extensions $extensions,
        private ProjectPaths $paths,
        private Environment $environment,
    ) {}

    /**
     * The URL of a file, publishing its assets/ folder if needed, e.g.
     * "/assets/acme/website/blocks/Teaser/assets/teaser.css".
     */
    public function url(string $file): string
    {
        [$package, $relative, $folder] = $this->locate($file);

        if (!file_exists($this->target($package, $folder))) {
            $this->publishFolder($package, $folder, false);
        }

        return self::PATH . '/' . $package . '/' . $relative;
    }

    /**
     * The URL with the time of the last change, so browsers load a changed
     * file again, e.g. "/assets/frootbox/backend/assets/build/ui.js?v=1761900000".
     */
    public function versioned(string $file): string
    {
        return $this->url($file) . '?v=' . (int) filemtime($file);
    }

    /**
     * Publishes every assets/ folder of every package again, e.g. after
     * a deployment. Returns the number of folders.
     */
    public function publishAll(): int
    {
        $count = 0;

        foreach ($this->packages() as $name => $root) {
            foreach ($this->folders($root) as $folder) {
                $this->publishFolder($name, $folder, true);
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Where generated files such as bundles go, e.g. public/assets/bundles.
     */
    public function directory(string $path = ''): string
    {
        return $this->paths->public(ltrim(self::PATH, '/') . ('' === $path ? '' : '/' . $path));
    }

    /**
     * The package of a file, its path within the package and its assets/ folder.
     *
     * @return array{string, string, string}
     */
    private function locate(string $file): array
    {
        $real = realpath($file);

        if (false === $real) {
            throw new RuntimeException(\sprintf('The asset %s does not exist.', $file));
        }

        // The most specific package wins, e.g. one nested in another.
        $packages = $this->packages();
        uasort($packages, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($packages as $name => $root) {
            if (!str_starts_with($real, $root . '/')) {
                continue;
            }

            $relative = substr($real, \strlen($root) + 1);
            $segments = explode('/', $relative);
            $index = array_search(self::FOLDER, array_reverse(\array_slice($segments, 0, -1), true), true);

            if (false === $index) {
                break;
            }

            return [$name, $relative, implode('/', \array_slice($segments, 0, $index + 1))];
        }

        throw new RuntimeException(\sprintf('%s is not inside an assets/ folder of an installed package.', $file));
    }

    private function publishFolder(string $package, string $folder, bool $replace): void
    {
        $source = $this->packages()[$package] . '/' . $folder;
        $target = $this->target($package, $folder);

        if (file_exists($target) || is_link($target)) {
            if (!$replace) {
                return;
            }

            self::remove($target);
        }

        self::makeDirectory(\dirname($target));

        if ($this->environment->isDevelopment()) {
            // Another request may have been faster.
            if (!@symlink($source, $target) && !is_link($target)) {
                throw new RuntimeException(\sprintf('Could not link %s to %s. Is public/assets writable?', $target, $source));
            }

            return;
        }

        $temporary = $target . '.' . bin2hex(random_bytes(4));
        self::copy($source, $temporary);

        if (!@rename($temporary, $target)) {
            self::remove($temporary);
        }
    }

    private function target(string $package, string $folder): string
    {
        return $this->directory($package . '/' . $folder);
    }

    /**
     * Installed packages and the core by name, with their real paths.
     *
     * @return array<string, string>
     */
    private function packages(): array
    {
        $packages = [];
        $core = realpath(\dirname(__DIR__, 2));

        if (false !== $core) {
            $packages[self::CORE] = $core;
        }

        foreach ($this->extensions as $package) {
            $path = realpath($package->path);

            if (false !== $path) {
                $packages[$package->name] = $path;
            }
        }

        return $packages;
    }

    /**
     * The assets/ folders of a package, relative to it.
     *
     * @return list<string>
     */
    private function folders(string $root): array
    {
        $folders = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo || !$item->isDir() || self::FOLDER !== $item->getFilename()) {
                continue;
            }

            $relative = substr($item->getPathname(), \strlen($root) + 1);

            // Nested assets/ folders come along with their parent; vendor and node_modules are not ours.
            if (!array_any($folders, static fn(string $folder): bool => str_starts_with($relative, $folder . '/'))
                && 1 !== preg_match('#(^|/)(vendor|node_modules|tests)/#', $relative . '/')) {
                $folders[] = $relative;
            }
        }

        sort($folders);

        return $folders;
    }

    private static function copy(string $source, string $target): void
    {
        self::makeDirectory($target);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $path = $target . '/' . substr($item->getPathname(), \strlen($source) + 1);

            if ($item->isDir()) {
                self::makeDirectory($path);
            } elseif (!copy($item->getPathname(), $path)) {
                throw new RuntimeException(\sprintf('Could not copy %s to %s.', $item->getPathname(), $path));
            }
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    private static function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new RuntimeException(\sprintf('Could not create %s. Is public/assets writable?', $path));
        }
    }
}
