<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
    ) {}
}
