<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Component\Option;

/**
 * A navigation the active theme shows, by name, e.g. "main" (decision 0106).
 */
final readonly class Definition
{
    public const string NAME_PATTERN = '^[a-z][a-z0-9-]{0,63}$';

    public const int MAX_DEPTH = 5;

    /**
     * @param int                   $depth   Levels of items
     * @param array<string, Option> $options Choices per item, e.g. "highlight"
     */
    public function __construct(
        public string $name,
        public string $title,
        public int $depth = 1,
        public array $options = [],
    ) {}
}
