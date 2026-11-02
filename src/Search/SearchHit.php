<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

/**
 * A thing the global search found (decision 0214): its source and id, so
 * every surface opens it its own way, e.g. the backend its screen.
 */
final readonly class SearchHit
{
    /**
     * @param list<string> $languages All languages it was found in, the shown one first
     */
    public function __construct(
        public string $source,
        public string $sourceTitle,
        public string $item,
        public string $title,
        public string $excerpt,
        public string $language,
        public array $languages,
        public int $score,
    ) {}
}
