<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Database\Entity;

/**
 * A category of a published object (decision 0233), e.g. of the article
 * "frootbox/news/article" 01J…. Drafts keep their categories themselves.
 */
final readonly class CategoryLink extends Entity
{
    public function __construct(
        public int $id,
        public string $categoryId,
        public string $targetType,
        public string $targetId,
    ) {}
}
