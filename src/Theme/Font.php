<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * A font editors can choose for a font token (decision 0127): the system
 * fonts of the core or one a theme lists under "fonts". With files the
 * core writes its @font-face and preloads what the page uses (decision
 * 0165); without, the theme ships them itself.
 */
final readonly class Font
{
    /**
     * @param list<FontFile> $files
     */
    public function __construct(
        public string $key,
        public string $label,
        /** The CSS font-family, with the fallback after the font */
        public string $family,
        /** The name in @font-face, e.g. "Noto Sans" */
        public string $face = '',
        public array $files = [],
        public ?FontFallback $fallback = null,
    ) {}

    /** The name of the adjusted fallback in @font-face. */
    public function fallbackFace(): string
    {
        return $this->face . ' Fallback';
    }

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
