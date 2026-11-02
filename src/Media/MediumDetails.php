<?php

/*
 * SPDX-License-Identifier: MIT
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
 * (decision 0147). A video (kind "video", decision 0177) has its codec,
 * its format to read (e.g. "MP4 (avc1)"), further files in $sources and
 * the address of its file as preview. A document (kind "document",
 * decision 0179) has its format, e.g. "PDF", and the address of its file.
 */
final readonly class MediumDetails
{
    /**
     * @param array{x: float, y: float}                       $focus
     * @param array<string, array{alt: string, title: string}> $texts by language
     * @param list<string>                                    $tags
     * @param list<array{id: string, type: string, codec: string, format: string, size: int, width: int, height: int, url: string}> $sources Further files of a video, in the order of <source>
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
        public string $kind = 'image',
        public string $codec = '',
        public array $sources = [],
        public string $format = '',
        public string $copyright = '',
    ) {}
}
