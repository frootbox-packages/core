<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Database\Repository;

/**
 * @extends Repository<MediaTag>
 */
final class MediaTags extends Repository
{
    public const string TABLE = 'media_tags';

    /**
     * @return list<string> sorted
     */
    public function of(string $mediaId): array
    {
        $tags = [];

        foreach ($this->fetch(['where' => ['mediaId' => $mediaId], 'order' => 'tag']) as $tag) {
            $tags[] = $tag->tag;
        }

        return $tags;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return MediaTag::class;
    }
}
