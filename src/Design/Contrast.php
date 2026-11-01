<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

/**
 * The contrast of two colours after WCAG 2: 1 for none, 21 for black on
 * white. Text needs at least 4.5 (decision 0127).
 */
final class Contrast
{
    public const float ENOUGH = 4.5;

    /**
     * @param string $first  Hex with six digits, e.g. "#8a4b2a"
     * @param string $second Hex with six digits
     */
    public static function ratio(string $first, string $second): float
    {
        $lighter = max(self::luminance($first), self::luminance($second));
        $darker = min(self::luminance($first), self::luminance($second));

        return round(($lighter + 0.05) / ($darker + 0.05), 2);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(
            static function (string $part): float {
                $value = hexdec($part) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($hex, 1, 6), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
