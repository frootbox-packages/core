<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

/**
 * How a path names an entry of a list (decision 0344): by its position,
 * e.g. "items/2/title", or by its id, e.g. "items/k3f9x2ab/title". A
 * position has at most 7 digits, an id always 8 characters, so the two
 * never mix up.
 */
final class EntryKey
{
    /** One segment naming an entry, for patterns of paths. */
    public const string SEGMENT = '(0|[1-9][0-9]{0,6}|[a-z0-9]{8})';

    /**
     * The position of the entry a segment names, null if the list has no
     * such entry (any more).
     */
    public static function position(mixed $entries, string $segment): ?int
    {
        if (!\is_array($entries) || !array_is_list($entries)) {
            return null;
        }

        if (1 === preg_match('/^(0|[1-9][0-9]{0,6})$/D', $segment)) {
            return \array_key_exists((int) $segment, $entries) ? (int) $segment : null;
        }

        if (1 !== preg_match('/^[a-z0-9]{8}$/D', $segment)) {
            return null;
        }

        foreach ($entries as $index => $entry) {
            if (\is_array($entry) && ($entry['id'] ?? null) === $segment) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether a segment names an entry by its id rather than its position.
     */
    public static function isId(string $segment): bool
    {
        return 8 === \strlen($segment) && 1 === preg_match('/^[a-z0-9]{8}$/D', $segment);
    }
}
