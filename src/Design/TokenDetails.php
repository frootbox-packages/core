<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

/**
 * A token editors may change, with its value in the draft, the published
 * one and the one of the theme (decision 0127).
 */
final readonly class TokenDetails
{
    /**
     * @param string                                                  $value     In the draft, or published if there is none
     * @param string                                                  $css       The value as CSS, e.g. the font family of a font key
     * @param list<array{value: string, label: string, css: string}> $options   For fonts and choices
     * @param array{token: string, ratio: float, enough: bool}|null   $contrast  Against which colour it is read and how well
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public string $value,
        public string $css,
        public string $published,
        public string $default,
        public array $options = [],
        public ?float $min = null,
        public ?float $max = null,
        public ?float $step = null,
        public ?string $unit = null,
        public ?array $contrast = null,
    ) {}
}
