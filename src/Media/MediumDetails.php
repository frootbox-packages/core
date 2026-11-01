<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use DateTimeImmutable;

/**
 * A medium as queries and actions give it: with focus point as shares
 * of 0 to 1, texts by language, tags and the address of a preview.
 * Existing is true when media.create found the same file in the library
 * and gave that medium instead of a new one (decision 0146). With a
 * draft, focus point, version and texts are those of the draft
 * (decision 0147).
 */
final readonly class MediumDetails
{
    /**
     * @param array{x: float, y: float}                       $focus
     * @param array<string, array{alt: string, title: string}> $texts by language
     * @param list<string>                                    $tags
     */
    public function __construct(
        public string $id,
        public ?string $folderId,
        public string $fileName,
        public string $type,
        public int $size,
        public int $width,
        public int $height,
        public array $focus,
        public int $version,
        public bool $public,
        public array $texts,
        public array $tags,
        public string $preview,
        public ?DateTimeImmutable $createdAt,
        public bool $existing = false,
        public bool $draft = false,
    ) {}
}
