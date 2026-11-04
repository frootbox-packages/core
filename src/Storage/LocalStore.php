<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

use FilesystemIterator;
use Frootbox\Core\Http\FileRangeStream;
use Psr\Http\Message\StreamInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A store in a folder, the default (decisions 0075, 0268): storage/ for
 * data, public/ for what the web server delivers itself. Files are
 * written to a temporary name first, so nobody reads half a file.
 */
final readonly class LocalStore implements FileStore
{
    private string $root;

    /**
     * @param string $label For messages, e.g. "storage/"
     */
    public function __construct(string $root, private string $label = '')
    {
        $this->root = rtrim($root, '/\\');
    }

    public function name(): string
    {
        return '' === $this->label ? $this->root : $this->label;
    }

    public function info(string $path): ?FileInfo
    {
        $file = $this->file($path);

        if (!is_file($file)) {
            return null;
        }

        clearstatcache(true, $file);

        return new FileInfo(StorePath::clean($path), (int) filesize($file), (int) filemtime($file));
    }

    public function exists(string $path): bool
    {
        return is_file($this->file($path));
    }

    public function read(string $path): ?string
    {
        $file = $this->file($path);
        $contents = is_file($file) ? @file_get_contents($file) : false;

        return false === $contents ? null : $contents;
    }

    public function readRange(string $path, int $start = 0, ?int $length = null): StreamInterface
    {
        $file = $this->file($path);

        if (!is_file($file)) {
            throw new StorageException(\sprintf('There is no file %s in %s.', $path, $this->name()));
        }

        $size = (int) filesize($file);

        return new FileRangeStream($file, $start, max(0, min($length ?? $size - $start, $size - $start)));
    }

    public function write(string $path, string $contents, ?string $type = null): void
    {
        $file = $this->prepare($path);
        $temporary = $file . '.' . bin2hex(random_bytes(4));

        if (false === @file_put_contents($temporary, $contents) || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new StorageException(\sprintf('Could not write %s in %s.', $path, $this->name()));
        }
    }

    public function writeFile(string $path, string $file, ?string $type = null, bool $move = false): void
    {
        $target = $this->prepare($path);

        if (realpath($file) === realpath($target)) {
            return;
        }

        if ($move && @rename($file, $target)) {
            return;
        }

        $temporary = $target . '.' . bin2hex(random_bytes(4));

        if (!@copy($file, $temporary) || !@rename($temporary, $target)) {
            @unlink($temporary);

            throw new StorageException(\sprintf('Could not write %s in %s.', $path, $this->name()));
        }

        if ($move) {
            @unlink($file);
        }
    }

    public function copyFrom(FileStore $source, string $from, string $to, ?string $type = null): void
    {
        $file = $source->localPath($from);

        if (!is_file($file)) {
            throw new StorageException(\sprintf('There is no file %s in %s.', $from, $source->name()));
        }

        $this->writeFile($to, $file, $type);
    }

    public function delete(string $path): void
    {
        $file = $this->file($path);

        if (is_file($file) || is_link($file)) {
            @unlink($file);
        }
    }

    public function deletePrefix(string $prefix): void
    {
        $prefix = StorePath::clean($prefix);

        if ('' !== $prefix) {
            self::removeTree($this->root . '/' . rtrim($prefix, '/'));
        }
    }

    public function list(string $prefix): iterable
    {
        $prefix = StorePath::clean($prefix);
        $folder = '' === $prefix ? $this->root : $this->root . '/' . rtrim($prefix, '/');

        if (!is_dir($folder)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile()) {
                $path = str_replace('\\', '/', substr($file->getPathname(), \strlen($this->root) + 1));

                yield new FileInfo($path, (int) $file->getSize(), (int) $file->getMTime());
            }
        }
    }

    public function touch(string $path): void
    {
        $file = $this->file($path);

        if (is_file($file)) {
            @touch($file);
        }
    }

    public function url(string $path): ?string
    {
        return null;
    }

    public function localPath(string $path): string
    {
        return $this->file($path);
    }

    public function stage(string $path): string
    {
        return $this->prepare($path);
    }

    public function commit(string $path, ?string $type = null, bool $keepLocal = true): void
    {
        if (!is_file($this->file($path))) {
            throw new StorageException(\sprintf('There is no file %s to store in %s.', $path, $this->name()));
        }
    }

    private function file(string $path): string
    {
        return $this->root . '/' . StorePath::clean($path);
    }

    /**
     * The file of a path, with its folder.
     */
    private function prepare(string $path): string
    {
        $file = $this->file($path);
        $folder = \dirname($file);

        if (!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) {
            throw new StorageException(\sprintf('Could not create the folder of %s in %s.', $path, $this->name()));
        }

        return $file;
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
