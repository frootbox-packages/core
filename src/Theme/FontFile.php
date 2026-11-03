<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * One file of a font a theme ships (decision 0165), e.g. the Latin
 * letters of a variable font in all weights.
 */
final readonly class FontFile
{
    public function __construct(
        /** Absolute path below assets/ of the theme */
        public string $path,
        /** "400" or a range such as "100 900" */
        public string $weight = '400',
        public string $style = 'normal',
        /** The unicode-range, null for all letters */
        public ?string $range = null,
        /** Loaded at once on pages whose tokens use the font */
        public bool $preload = false,
        /** The address of a file outside of assets/, e.g. one of StoredFonts (decision 0252) */
        public ?string $url = null,
    ) {}

    /** The format for @font-face, from the extension. */
    public function format(): string
    {
        return match (strtolower(pathinfo($this->path, \PATHINFO_EXTENSION))) {
            'woff2' => 'woff2',
            'woff' => 'woff',
            'otf' => 'opentype',
            default => 'truetype',
        };
    }
}
