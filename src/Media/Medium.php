<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * An image, video or document of the media library (decisions 0126,
 * 0177, 0179). The original lies in storage/media/ under the name in $file, the id for the first file
 * (decision 0147); the focus point is in thousandths of width and
 * height. The version grows with every change that alters the sizes.
 * The hash is the SHA-256 of the uploaded file, none for media from
 * before decision 0146. The codec is only known for videos. The
 * copyright names the photographer or image agency (decision 0203), ai
 * whether an AI made or changed it (AiOrigin, decision 0251).
 */
final readonly class Medium extends Entity
{
    public function __construct(
        public string $id,
        public ?string $folderId,
        public string $fileName,
        public string $type,
        public int $size,
        public int $width,
        public int $height,
        public int $focusX,
        public int $focusY,
        public int $version,
        public bool $public,
        public ?string $hash,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public ?string $file = null,
        public string $codec = '',
        public string $copyright = '',
        public string $ai = 'none',
    ) {}

    /**
     * Whether an AI made or changed the medium (decision 0251).
     */
    public function aiOrigin(): AiOrigin
    {
        return AiOrigin::of($this->ai);
    }

    /**
     * Whether the medium is a video (decision 0177).
     */
    public function isVideo(): bool
    {
        return Videos::isVideoType($this->type);
    }

    /**
     * Whether the medium is a document, e.g. a PDF (decision 0179).
     */
    public function isDocument(): bool
    {
        return Documents::isDocumentType($this->type);
    }

    /**
     * Whether the medium is an SVG graphic, an image without sizes (decision 0182).
     */
    public function isVector(): bool
    {
        return Svg::TYPE === $this->type;
    }

    /**
     * Whether the medium is an image: neither video nor document, also an SVG.
     */
    public function isImage(): bool
    {
        return !$this->isVideo() && !$this->isDocument();
    }

    /**
     * "image", "video" or "document".
     */
    public function kind(): string
    {
        return match (true) {
            $this->isVideo() => 'video',
            $this->isDocument() => 'document',
            default => 'image',
        };
    }

    /**
     * The name of the original below storage/media/.
     */
    public function original(): string
    {
        return $this->file ?? $this->id;
    }
}
