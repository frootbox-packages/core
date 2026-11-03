<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

/**
 * A group of categories for editors (decision 0233).
 */
final readonly class GroupSummary
{
    /**
     * @param list<array{type: string, label: string}> $types
     */
    public function __construct(
        public string $key,
        /** Translated for a group of an extension */
        public string $title,
        public array $types,
        public GroupSource $source,
        public int $count,
        /** How many presets have no category yet (decision 0239) */
        public int $missing = 0,
    ) {}
}
