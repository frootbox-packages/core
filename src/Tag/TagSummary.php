<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

/**
 * A tag with how many published objects have it (decision 0233).
 */
final readonly class TagSummary
{
    /**
     * @param array<string, string> $labels By language
     */
    public function __construct(
        public string $id,
        public string $key,
        public array $labels,
        public bool $internal,
        /** Whether the configuration or an extension sets it out */
        public bool $preset,
        public int $count,
    ) {}
}
