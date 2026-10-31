<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Support;

use InvalidArgumentException;

/**
 * Sort keys for ordered lists (fractional indexing): a key between two
 * others can always be found, so moving an item changes only that item.
 *
 *     SortKey::between(null, null); // "i"
 *     SortKey::between('i', null);  // "r", after "i"
 *     SortKey::between('i', 'r');   // "n", between them
 *
 * Keys use 0-9 and a-z, compare as plain strings and never end with "0".
 */
final class SortKey
{
    public const string PATTERN = '^[0-9a-z]*[1-9a-z]$';

    private const string DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * A key after $before and before $after. Null means the start or the
     * end of the list.
     */
    public static function between(?string $before, ?string $after): string
    {
        foreach ([$before, $after] as $key) {
            if (null !== $key && !self::isValid($key)) {
                throw new InvalidArgumentException(\sprintf('"%s" is not a sort key.', $key));
            }
        }

        if (null !== $before && null !== $after && strcmp($before, $after) >= 0) {
            throw new InvalidArgumentException(\sprintf('The sort key "%s" must come before "%s".', $before, $after));
        }

        return self::midpoint($before ?? '', $after);
    }

    /**
     * Keys for a whole list in its order, e.g. for new content.
     *
     * @return list<string>
     */
    public static function sequence(int $count): array
    {
        $keys = [];
        $last = null;

        for ($i = 0; $i < $count; ++$i) {
            $keys[] = $last = self::between($last, null);
        }

        return $keys;
    }

    public static function isValid(string $key): bool
    {
        return 1 === preg_match('/' . self::PATTERN . '/', $key);
    }

    /**
     * The shortest key between $a and $b, as digits after the point of a
     * number between 0 and 1. $b null stands for 1.
     */
    private static function midpoint(string $a, ?string $b): string
    {
        if (null !== $b) {
            $common = 0;

            while (($a[$common] ?? '0') === ($b[$common] ?? null)) {
                ++$common;
            }

            if ($common > 0) {
                return substr($b, 0, $common) . self::midpoint(substr($a, $common), substr($b, $common));
            }
        }

        $digitA = '' === $a ? 0 : (int) strpos(self::DIGITS, $a[0]);
        $digitB = null === $b ? \strlen(self::DIGITS) : (int) strpos(self::DIGITS, $b[0]);

        if ($digitB - $digitA > 1) {
            return self::DIGITS[(int) round(($digitA + $digitB) / 2)];
        }

        if (null !== $b && \strlen($b) > 1) {
            return $b[0];
        }

        return self::DIGITS[$digitA] . self::midpoint(substr($a, 1), null);
    }
}
