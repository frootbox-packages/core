<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

/**
 * One line of the image credits (decision 0258): a published medium with a
 * copyright and the pages that show it to visitors.
 */
final readonly class Credit
{
    /**
     * @param list<CreditPage> $pages
     */
    public function __construct(
        public string $id,
        public string $copyright,
        public string $fileName,
        /** A small image to recognise it by, null for documents and videos. */
        public ?string $preview,
        /** Size of the small image in pixels, 0 without one. */
        public int $previewWidth,
        public int $previewHeight,
        /** Used in the fields of the website, so on every page. */
        public bool $everywhere,
        public array $pages,
    ) {}
}
