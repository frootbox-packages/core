<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * The published structure and layout of a page, shared by all languages
 * (decision 0099). Drafts live in revisions.
 */
final readonly class Page extends Entity
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $position,
        public string $layout,
        public ?string $variant,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        /** Visitors get 503, right away and not part of the draft (decision 0117) */
        public Reach $locked = Reach::None,
        public Reach $excludeFromSearchEngines = Reach::None,
        public Reach $excludeFromSiteSearch = Reach::None,
        /** The space of a plugin the page shows (decision 0232) */
        public ?string $pluginType = null,
        public ?string $pluginSpace = null,
        public bool $pluginDetails = false,
        public bool $pluginCategories = false,
    ) {}
}
