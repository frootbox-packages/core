<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Database\Repository;

/**
 * @extends Repository<MediaSource>
 */
final class MediaSources extends Repository
{
    public const string TABLE = 'media_sources';

    /**
     * The sources of a video, ordered for <source>: the most efficient
     * codec first (decision 0177).
     *
     * @return list<MediaSource>
     */
    public function of(string $mediaId): array
    {
        $sources = $this->fetch(['where' => ['mediaId' => $mediaId]])->all();
        usort($sources, static fn(MediaSource $one, MediaSource $other): int => [Videos::rank($one->codec), $one->id] <=> [Videos::rank($other->codec), $other->id]);

        return $sources;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return MediaSource::class;
    }
}
