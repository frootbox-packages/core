<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

/**
 * A file of a theme standing in for a medium in the example of a block
 * (decision 0206), resolved by the design page under the key "sample".
 * Only that page puts it into a value; stored values cannot hold it, as
 * they come from JSON.
 */
final readonly class Sample
{
    public const string KEY = 'sample';

    public function __construct(
        public string $url,
        /** The media type, e.g. "image/jpeg" */
        public string $type,
        public string $name,
        /** In bytes */
        public int $size,
        /** Of an image or video, 0 if unknown */
        public int $width = 0,
        public int $height = 0,
        /** URL of the still of a video */
        public ?string $poster = null,
    ) {}

    public static function of(mixed $value): ?self
    {
        $sample = \is_array($value) ? $value[self::KEY] ?? null : null;

        return $sample instanceof self ? $sample : null;
    }
}
