<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

/**
 * Whether a stored value holds nothing an editor entered (decision 0144):
 * null, text of only spaces, or a list or object of such values, e.g. a
 * list entry whose fields were never filled.
 */
final class Blank
{
    public static function is(mixed $value): bool
    {
        return match (true) {
            null === $value => true,
            \is_string($value) => '' === trim($value),
            \is_array($value) => [] === array_filter($value, static fn(mixed $item): bool => !self::is($item)),
            $value instanceof \stdClass => self::is((array) $value),
            default => false,
        };
    }
}
