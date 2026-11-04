<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

use Psr\Http\Message\StreamInterface;

/**
 * Where files lie that are data (decision 0268): a folder or a bucket.
 * Paths are relative with forward slashes, e.g. "media/01J…". The core
 * has two stores, see FileStores; other kinds come from extensions as a
 * StorageDriver.
 *
 * Code that needs a file on disk, e.g. to resize an image, asks for
 * localPath(). New files are written to stage() and stored with
 * commit(), so a bucket gets them without a second copy.
 */
interface FileStore
{
    /** For messages, e.g. "storage/" or "s3://kunde-daten". */
    public function name(): string;

    public function info(string $path): ?FileInfo;

    public function exists(string $path): bool;

    /**
     * The whole file, null if there is none. Only for small files.
     */
    public function read(string $path): ?string;

    /**
     * Bytes of a file as they are read, e.g. for a response with Range.
     *
     * @param int|null $length Null up to the end
     *
     * @throws StorageException If there is no such file
     */
    public function readRange(string $path, int $start = 0, ?int $length = null): StreamInterface;

    /**
     * @param string|null $type The media type, for stores that keep one
     */
    public function write(string $path, string $contents, ?string $type = null): void;

    /**
     * Stores a file on disk; it is moved if $move and the store is a
     * folder on the same disk, else copied.
     */
    public function writeFile(string $path, string $file, ?string $type = null, bool $move = false): void;

    /**
     * Stores the file of another store, within a bucket without a detour
     * if the stores allow it.
     */
    public function copyFrom(FileStore $source, string $from, string $to, ?string $type = null): void;

    public function delete(string $path): void;

    /**
     * Deletes all files below a folder, e.g. "media/01J…/".
     */
    public function deletePrefix(string $prefix): void;

    /**
     * All files below a folder, at any depth.
     *
     * @return iterable<FileInfo>
     */
    public function list(string $prefix): iterable;

    /**
     * Gives a file the time of now as its last change.
     */
    public function touch(string $path): void;

    /**
     * The address of a file a browser can load directly, null if the
     * store is not reachable from outside or the core's own address
     * serves it (a folder below public/).
     */
    public function url(string $path): ?string;

    /**
     * A file on disk with the contents, fetched into var/storage/ if
     * needed. If there is no such file, the path returned does not exist.
     */
    public function localPath(string $path): string;

    /**
     * Where to write a new file on disk that commit() then stores. The
     * folder exists.
     */
    public function stage(string $path): string;

    /**
     * Stores what was written to stage().
     *
     * @param bool $keepLocal Whether the copy on disk may stay, e.g. for
     *                        an image whose sizes are generated next
     */
    public function commit(string $path, ?string $type = null, bool $keepLocal = true): void;
}
