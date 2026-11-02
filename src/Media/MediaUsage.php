<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Database\Entity;

/**
 * One place that uses a medium (decision 0146): a page or the fields of
 * the website, in one language, in the draft or the published state.
 */
final readonly class MediaUsage extends Entity
{
    public function __construct(
        public int $id,
        public string $mediaId,
        public string $ownerType,
        public string $ownerId,
        public string $language,
        public bool $published,
    ) {}

    public function key(): string
    {
        return self::keyOf($this->mediaId, $this->language, $this->published);
    }

    public static function keyOf(string $mediaId, string $language, bool $published): string
    {
        return $mediaId . '|' . $language . '|' . ($published ? '1' : '0');
    }
}
