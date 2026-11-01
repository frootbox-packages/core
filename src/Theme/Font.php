<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * A font editors can choose for a font token (decision 0127): the system
 * fonts of the core or one a theme lists under "fonts" and ships itself
 * with @font-face in its styles.
 */
final readonly class Font
{
    public function __construct(
        public string $key,
        public string $label,
        public string $family,
    ) {}

    /**
     * Fonts every device has, no files to load.
     *
     * @return array<string, self>
     */
    public static function system(): array
    {
        $fonts = [
            new self('sans', 'Sans-serif', 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'),
            new self('serif', 'Serif', 'ui-serif, Georgia, Cambria, "Times New Roman", serif'),
            new self('rounded', 'Rounded', 'ui-rounded, "SF Pro Rounded", "Arial Rounded MT", Quicksand, Nunito, sans-serif'),
            new self('mono', 'Monospace', 'ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, monospace'),
        ];

        return array_column($fonts, null, 'key');
    }
}
