<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * A short name for the device of a session from its user agent, e.g.
 * "Chrome · macOS", kept at the token and in the log of actions
 * (decision 0174). Null if neither browser nor system is known.
 */
final class Device
{
    private const array BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    private const array SYSTEMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Macintosh' => 'macOS',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public static function describe(?string $userAgent): ?string
    {
        if (null === $userAgent || '' === $userAgent) {
            return null;
        }

        $parts = array_filter([self::first(self::BROWSERS, $userAgent), self::first(self::SYSTEMS, $userAgent)], static fn(?string $part): bool => null !== $part);

        return [] === $parts ? null : implode(' · ', $parts);
    }

    /**
     * @param array<string, string> $names
     */
    private static function first(array $names, string $userAgent): ?string
    {
        foreach ($names as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
