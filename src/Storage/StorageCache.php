<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

use FilesystemIterator;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Media\MediaFiles;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Schedule\ScheduleRan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * After a run of the scheduler (decision 0268): empties the page cache
 * once new files are in the public bucket, so the pages get their
 * addresses there instead of going through PHP; and at most once an hour
 * removes copies on disk of files in a bucket that were not used for a
 * day. A store touches a copy each time it hands it out.
 */
final readonly class StorageCache
{
    public const int LIFETIME = 86400;

    private const int EVERY = 3600;

    public function __construct(
        private ProjectPaths $paths,
        private PageCache $pages,
    ) {}

    public function __invoke(ScheduleRan $event): void
    {
        $new = $this->paths->var(MediaFiles::NEW_MARKS);

        if (is_file($new) && @unlink($new)) {
            $this->pages->clear();
        }

        $folder = $this->paths->var('storage');
        $stamp = $folder . '/.pruned';
        $now = $event->now->getTimestamp();

        if (!is_dir($folder) || (is_file($stamp) && $now - (int) filemtime($stamp) < self::EVERY)) {
            return;
        }

        @touch($stamp, $now);
        $this->prune($now);
    }

    /**
     * @return int How many copies were removed
     */
    public function prune(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;

        foreach (StoreRole::cases() as $role) {
            $folder = $this->paths->var('storage/' . $role->value);

            if (!is_dir($folder)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } elseif ($file->getMTime() < $now - self::LIFETIME) {
                    @unlink($file->getPathname());
                    ++$removed;
                }
            }
        }

        return $removed;
    }
}
