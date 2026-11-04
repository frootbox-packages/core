<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * The published title, slug, content, description and title for search
 * engines of a page in one language.
 */
final readonly class PageTranslation extends Entity
{
    /**
     * @param array<mixed> $content
     */
    public function __construct(
        public int $id,
        public string $pageId,
        public string $language,
        public string $title,
        public string $slug,
        public array $content,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public string $description = '',
        public string $seoTitle = '',
    ) {}
}
