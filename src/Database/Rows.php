<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

/**
 * Compares rows independent of how the driver returns values: 5, "5" and
 * true/"1" are the same.
 */
final class Rows
{
    /**
     * @param array<string, mixed>|null $a
     * @param array<string, mixed>|null $b
     */
    public static function same(?array $a, ?array $b): bool
    {
        if (null === $a || null === $b) {
            return $a === $b;
        }

        return self::normalize($a) === self::normalize($b);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string|null>
     */
    private static function normalize(array $row): array
    {
        $normalized = [];

        foreach ($row as $column => $value) {
            $normalized[strtolower($column)] = match (true) {
                null === $value => null,
                \is_bool($value) => $value ? '1' : '0',
                \is_scalar($value) => (string) $value,
                default => json_encode($value, \JSON_THROW_ON_ERROR),
            };
        }

        ksort($normalized);

        return $normalized;
    }
}
