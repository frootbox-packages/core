<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Database\Entity;

/**
 * A tag of a medium.
 */
final readonly class MediaTag extends Entity
{
    public function __construct(
        public int $id,
        public string $mediaId,
        public string $tag,
    ) {}
}
