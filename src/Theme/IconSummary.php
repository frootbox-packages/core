<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * One symbol in the result of icon.list.
 */
final readonly class IconSummary
{
    public function __construct(
        /** The value of an icon field, e.g. "truck" */
        public string $name,
        /** In the language of the backend, e.g. "Lieferwagen" */
        public string $label,
        /** The markup the page gets */
        public string $svg,
        /** The set it belongs to, e.g. "theme" */
        public string $set,
    ) {}
}
