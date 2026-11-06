<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai\Writing;

/**
 * A title and a description for search engines the AI suggests for a
 * page (decision 0296); page.update saves them as seoTitle and description.
 */
final readonly class SearchSnippet
{
    public function __construct(
        public string $title,
        public string $description,
    ) {}
}
