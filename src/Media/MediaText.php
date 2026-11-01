<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Database\Entity;

/**
 * Alternative text and title of a medium in one language.
 */
final readonly class MediaText extends Entity
{
    public function __construct(
        public int $id,
        public string $mediaId,
        public string $language,
        public string $alt,
        public string $title,
    ) {}
}
