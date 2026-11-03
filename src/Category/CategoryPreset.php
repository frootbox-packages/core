<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

/**
 * A category a group brings along (decision 0239), with a fixed key, a
 * title by language and the presets below it.
 *
 *     new CategoryPreset('courses', ['de' => 'Kurse', 'en' => 'Courses'], [new CategoryPreset('latte-art', ['de' => 'Latte Art'])])
 */
final readonly class CategoryPreset
{
    /**
     * @param array<string, string> $titles   By language
     * @param list<CategoryPreset>  $children
     */
    public function __construct(
        public string $key,
        public array $titles,
        public array $children = [],
    ) {}
}
