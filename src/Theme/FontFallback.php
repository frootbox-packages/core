<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * A font on the device, adjusted to the metrics of a web font, so the
 * text keeps its place while the web font loads (decision 0165).
 */
final readonly class FontFallback
{
    /**
     * @param list<string> $local
     */
    public function __construct(
        /** Font names for local(), e.g. ["Arial", "Helvetica"] */
        public array $local,
        /** Percentages such as "105.37%", null to leave out */
        public ?string $sizeAdjust = null,
        public ?string $ascent = null,
        public ?string $descent = null,
        public ?string $lineGap = null,
    ) {}

    /**
     * The @font-face of the adjusted font under the given name.
     */
    public function css(string $face): string
    {
        $css = 'font-family:"' . $face . '";src:' . implode(',', array_map(static fn(string $name): string => 'local("' . $name . '")', $this->local)) . ';';

        foreach (['size-adjust' => $this->sizeAdjust, 'ascent-override' => $this->ascent, 'descent-override' => $this->descent, 'line-gap-override' => $this->lineGap] as $property => $value) {
            if (null !== $value) {
                $css .= $property . ':' . $value . ';';
            }
        }

        return '@font-face{' . $css . '}';
    }
}
