<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use BackedEnum;
use DateTimeInterface;
use Frootbox\Core\Database\Entity;
use JsonSerializable;
use Traversable;
use UnitEnum;

/**
 * Turns results and inputs into plain values that fit into JSON:
 * entities become arrays, dates ISO 8601 strings, enums their value.
 */
final class Normalizer
{
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            null === $value, \is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Entity => self::normalize($value->toArray()),
            $value instanceof JsonSerializable => self::normalize($value->jsonSerialize()),
            $value instanceof Traversable => array_map(self::normalize(...), iterator_to_array($value)),
            \is_array($value) => array_map(self::normalize(...), $value),
            \is_object($value) => self::normalize(get_object_vars($value)),
            default => throw new \UnexpectedValueException(\sprintf('Cannot normalize a value of type %s.', get_debug_type($value))),
        };
    }
}
