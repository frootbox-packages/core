<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use BackedEnum;
use DateTimeInterface;

/**
 * Column names and values on their way into the database.
 */
final class Values
{
    public const string DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Turns a property name into its column: "parentId" becomes "parent_id".
     * Column names stay as they are.
     */
    public static function column(string $name): string
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RepositoryException(\sprintf('"%s" is not a valid column name.', $name));
        }

        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name));
    }

    /**
     * @return float|int|string|null
     */
    public static function toDatabase(mixed $value): mixed
    {
        return match (true) {
            null === $value, \is_int($value), \is_float($value), \is_string($value) => $value,
            \is_bool($value) => $value ? 1 : 0,
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(self::DATE_FORMAT),
            \is_array($value) => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION),
            default => throw new RepositoryException(\sprintf('A value of type %s cannot be stored.', get_debug_type($value))),
        };
    }

    /**
     * Converts the keys and values of a row for writing.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, float|int|string|null>
     */
    public static function row(array $values): array
    {
        $row = [];

        foreach ($values as $name => $value) {
            $row[self::column($name)] = self::toDatabase($value);
        }

        return $row;
    }
}
