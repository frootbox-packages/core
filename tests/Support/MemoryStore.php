<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use Frootbox\Core\Storage\Dsn;
use Frootbox\Core\Storage\FileInfo;
use Frootbox\Core\Storage\FileStore;
use Frootbox\Core\Storage\StorageException;
use Frootbox\Core\Storage\StorePath;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * A store like a bucket, in memory, for tests (decision 0268):
 * STORAGE_DSN=memory://default, PUBLIC_STORAGE_DSN=memory://default?url=https://bucket.example
 * Copies on disk go below the cache folder, like with S3.
 */
final class MemoryStore implements FileStore
{
    /** @var array<string, array{contents: string, type: ?string, modified: int}> */
    public array $files = [];

    /** @var list<string> What was asked of the store, e.g. "copy media/01J…" */
    public array $calls = [];

    public function __construct(
        private readonly Dsn $dsn,
        private readonly string $cache,
    ) {}

    public function name(): string
    {
        return 'memory://' . $this->cache;
    }

    public function info(string $path): ?FileInfo
    {
        $path = StorePath::clean($path);
        $file = $this->files[$path] ?? null;

        return null === $file ? null : new FileInfo($path, \strlen($file['contents']), $file['modified']);
    }

    public function exists(string $path): bool
    {
        $this->calls[] = 'exists ' . $path;

        return isset($this->files[StorePath::clean($path)]);
    }

    public function read(string $path): ?string
    {
        return $this->files[StorePath::clean($path)]['contents'] ?? null;
    }

    public function readRange(string $path, int $start = 0, ?int $length = null): StreamInterface
    {
        $contents = $this->read($path) ?? throw new StorageException('There is no file ' . $path . '.');

        return Stream::create(substr($contents, $start, $length));
    }

    public function write(string $path, string $contents, ?string $type = null): void
    {
        $this->files[StorePath::clean($path)] = ['contents' => $contents, 'type' => $type, 'modified' => time()];
    }

    public function writeFile(string $path, string $file, ?string $type = null, bool $move = false): void
    {
        $this->write($path, (string) file_get_contents($file), $type);

        if ($move) {
            @unlink($file);
        }
    }

    public function copyFrom(FileStore $source, string $from, string $to, ?string $type = null): void
    {
        $this->calls[] = 'copy ' . $from;
        $contents = $source->read($from) ?? throw new StorageException('There is no file ' . $from . '.');
        $this->write($to, $contents, $type);
    }

    public function delete(string $path): void
    {
        $path = StorePath::clean($path);
        unset($this->files[$path]);
        @unlink($this->cache . '/' . $path);
    }

    public function deletePrefix(string $prefix): void
    {
        foreach (array_keys($this->files) as $path) {
            if (str_starts_with($path, StorePath::clean($prefix))) {
                $this->delete($path);
            }
        }
    }

    public function list(string $prefix): iterable
    {
        foreach ($this->files as $path => $file) {
            if (str_starts_with($path, StorePath::clean($prefix))) {
                yield new FileInfo($path, \strlen($file['contents']), $file['modified']);
            }
        }
    }

    public function touch(string $path): void
    {
        $path = StorePath::clean($path);

        if (isset($this->files[$path])) {
            $this->files[$path]['modified'] = time();
        }
    }

    public function url(string $path): ?string
    {
        $base = $this->dsn->option('url');

        return '' === $base ? null : rtrim($base, '/') . '/' . StorePath::clean($path);
    }

    public function localPath(string $path): string
    {
        $file = $this->cache . '/' . StorePath::clean($path);
        $contents = $this->read($path);

        if (null !== $contents && !is_file($file)) {
            $this->calls[] = 'fetch ' . $path;
            @mkdir(\dirname($file), 0o775, true);
            file_put_contents($file, $contents);
        }

        return $file;
    }

    public function stage(string $path): string
    {
        $file = $this->cache . '/' . StorePath::clean($path);
        @mkdir(\dirname($file), 0o775, true);

        return $file;
    }

    public function commit(string $path, ?string $type = null, bool $keepLocal = true): void
    {
        $file = $this->stage($path);
        $this->write($path, (string) file_get_contents($file), $type);

        if (!$keepLocal) {
            @unlink($file);
        }
    }
}
