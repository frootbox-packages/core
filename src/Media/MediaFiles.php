<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Storage\FileStore;
use Frootbox\Core\Storage\FileStores;
use Frootbox\Core\Storage\StorageException;
use Psr\Http\Message\StreamInterface;

/**
 * Where the files of media lie (decisions 0075, 0268): originals below
 * media/ in the store for data, generated sizes and copies below
 * media/<id>/ in the public store. Both are folders (storage/, public/)
 * or buckets. The first original of a medium is named by its id, each
 * replacing one by the id and a random part (decision 0147), so an
 * original never changes and a copy on disk stays right.
 *
 * A public store in a bucket has its own address. Once a file is there,
 * a mark below var/storage/marks/ says so, and the address of the bucket
 * goes into the page.
 */
final readonly class MediaFiles
{
    private const string FOLDER = 'media/';

    /** Not below var/cache/, so cache:clear after a deployment keeps them. */
    public const string MARKS = 'storage/marks/';

    /** A new mark, so pages in the cache get the addresses of the bucket (see StorageCache). */
    public const string NEW_MARKS = 'storage/marks/.new';

    public function __construct(
        private FileStores $stores,
        private ProjectPaths $paths,
    ) {}

    /**
     * A file on disk with the original, fetched from a bucket if needed.
     * The path does not exist if there is no such original.
     *
     * @param string $name The id for the first file of a medium, see Medium::original()
     */
    public function original(string $name): string
    {
        return $this->storage()->localPath(self::FOLDER . $name);
    }

    /**
     * Where to write a new original on disk; keep() stores it.
     */
    public function target(string $name): string
    {
        return $this->storage()->stage(self::FOLDER . $name);
    }

    /**
     * Stores an original written to target().
     *
     * @param bool $keepLocal False for large files nobody resizes, e.g. videos
     */
    public function keep(string $name, string $type, bool $keepLocal = true): void
    {
        $this->storage()->commit(self::FOLDER . $name, $type, $keepLocal);
    }

    /**
     * Removes an original that never got a medium, e.g. a file that
     * turned out to be no video.
     */
    public function drop(string $name): void
    {
        try {
            $this->storage()->delete(self::FOLDER . $name);
        } catch (StorageException) {
            // media:prune takes it later.
        }
    }

    public function size(string $name): ?int
    {
        return $this->storage()->info(self::FOLDER . $name)?->size;
    }

    /**
     * Bytes of an original as they are read, for a response with Range.
     */
    public function stream(string $name, int $start, int $length): StreamInterface
    {
        return $this->storage()->readRange(self::FOLDER . $name, $start, $length);
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
        try {
            $this->storage()->touch(self::FOLDER . $name);
        } catch (StorageException) {
            // At worst it goes a little earlier.
        }
    }

    /**
     * The file on disk of a generated file in the folder public/media/;
     * with a bucket only a copy, if any.
     *
     * @param string $path Below /media/, e.g. "01J…/1/kaffee-640x0-….webp"
     */
    public function public(string $path): string
    {
        return $this->stores->public()->localPath(self::FOLDER . ltrim($path, '/'));
    }

    /**
     * The address of a generated file in a public bucket, null if the
     * public store is the folder or the file is not known to be there.
     *
     * @param bool $check Ask the bucket if there is no mark, e.g. after var/cache/ was emptied
     */
    public function publicUrl(string $path, bool $check = false): ?string
    {
        $path = ltrim($path, '/');
        $store = $this->stores->public();
        $url = $store->url(self::FOLDER . $path);

        if (null === $url) {
            return null;
        }

        if (is_file($this->mark($path))) {
            return $url;
        }

        try {
            if ($check && $store->exists(self::FOLDER . $path)) {
                $this->markPublic($path);

                return $url;
            }
        } catch (StorageException) {
            return null;
        }

        return null;
    }

    /**
     * Writes a generated file so the web server or the bucket delivers it
     * from then on.
     *
     * @return string|null The address in the bucket, null for the folder
     */
    public function writePublic(string $path, string $contents, string $type): ?string
    {
        $path = ltrim($path, '/');

        try {
            $this->stores->public()->write(self::FOLDER . $path, $contents, $type);
        } catch (StorageException) {
            return null;
        }

        return $this->markPublic($path);
    }

    /**
     * Copies an original, e.g. of a video, so the web server or the bucket
     * delivers it from then on (decision 0177).
     *
     * @return string|null The address in the bucket, null for the folder or if it failed
     */
    public function copyPublic(string $name, string $path, string $type): ?string
    {
        $path = ltrim($path, '/');
        $known = $this->publicUrl($path);

        if (null !== $known) {
            return $known;
        }

        $public = $this->stores->public();

        try {
            if (!$public->exists(self::FOLDER . $path)) {
                $public->copyFrom($this->storage(), self::FOLDER . $name, self::FOLDER . $path, $type);
            }
        } catch (StorageException) {
            return null;
        }

        return $this->markPublic($path);
    }

    /**
     * Removes all generated files of a medium.
     */
    public function removePublic(string $id): void
    {
        try {
            $this->stores->public()->deletePrefix(self::FOLDER . $id . '/');
        } catch (StorageException) {
            // Without a mark they are not used; media:prune does not see them, a new version has new addresses.
        }

        self::removeTree($this->paths->var(self::MARKS . $id));
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
        $store = $this->storage();

        foreach ($store->list(self::FOLDER) as $file) {
            $name = substr($file->path, \strlen(self::FOLDER));

            // Only originals lie directly in media/.
            if (str_contains($name, '/') || $file->modified >= $now - $age || $used($name)) {
                continue;
            }

            $store->delete($file->path);
            $id = explode('-', $name, 2)[0];

            if (!$exists($id)) {
                $this->removePublic($id);
            }

            ++$removed;
        }

        return $removed;
    }

    private function storage(): FileStore
    {
        return $this->stores->storage();
    }

    private function mark(string $path): string
    {
        return $this->paths->var(self::MARKS . $path);
    }

    /**
     * @return string|null The address in the bucket
     */
    private function markPublic(string $path): ?string
    {
        $url = $this->stores->public()->url(self::FOLDER . $path);

        if (null !== $url) {
            $mark = $this->mark($path);
            $folder = \dirname($mark);

            if (!is_file($mark) && (is_dir($folder) || @mkdir($folder, 0o775, true) || is_dir($folder))) {
                @touch($mark);
                @touch($this->paths->var(self::NEW_MARKS));
            }
        }

        return $url;
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
