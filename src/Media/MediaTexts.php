<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Database\Repository;

/**
 * @extends Repository<MediaText>
 */
final class MediaTexts extends Repository
{
    public const string TABLE = 'media_texts';

    /**
     * @return array<string, MediaText> by language
     */
    public function of(string $mediaId): array
    {
        $texts = [];

        foreach ($this->fetch(['where' => ['mediaId' => $mediaId]]) as $text) {
            $texts[$text->language] = $text;
        }

        return $texts;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return MediaText::class;
    }
}
