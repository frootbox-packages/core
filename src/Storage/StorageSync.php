<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

/**
 * Copies the data of the store for data between the folder storage/ and
 * a bucket (decision 0268): originals of media and files from forms. The
 * public store needs nothing, its files are generated again.
 */
final readonly class StorageSync
{
    /** What lies in the store for data; uploads in parts, fonts and backups stay on disk. */
    public const array FOLDERS = ['media/', 'private/forms/'];

    /**
     * @param callable(FileInfo, bool): void|null $each Gets each file and whether it was copied
     *
     * @return array{copied: int, same: int, bytes: int}
     */
    public static function copy(FileStore $from, FileStore $to, bool $dryRun = false, ?callable $each = null): array
    {
        $result = ['copied' => 0, 'same' => 0, 'bytes' => 0];

        foreach (self::FOLDERS as $folder) {
            foreach ($from->list($folder) as $file) {
                if ($to->info($file->path)?->size === $file->size) {
                    ++$result['same'];

                    if (null !== $each) {
                        $each($file, false);
                    }

                    continue;
                }

                if (!$dryRun) {
                    $to->copyFrom($from, $file->path, $file->path);
                }

                ++$result['copied'];
                $result['bytes'] += $file->size;

                if (null !== $each) {
                    $each($file, true);
                }
            }
        }

        return $result;
    }
}
