<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Schema;

use BackedEnum;
use DateTimeImmutable;
use Frootbox\Core\Action\Normalizer;
use ReflectionClass;

/**
 * Converts between an action object and its input as plain values.
 */
final class InputMapper
{
    public const string MASK = '***';

    /**
     * The input of an action or query as plain values, e.g. for validation,
     * the log or the REST API.
     *
     * @return array<string, mixed>
     */
    public static function fromObject(object $object): array
    {
        $values = get_object_vars($object);
        $input = [];

        foreach (Fields::of($object::class) as $field) {
            $input[$field->name] = Normalizer::normalize($values[$field->name] ?? null);
        }

        return $input;
    }

    /**
     * Replaces secrets such as passwords with ***.
     *
     * @param class-string         $class
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function mask(string $class, array $input): array
    {
        foreach (Fields::of($class) as $field) {
            if ($field->isSensitive() && isset($input[$field->name])) {
                $input[$field->name] = self::MASK;
            }
        }

        return $input;
    }

    /**
     * Creates the object from validated input.
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $input
     *
     * @return T
     */
    public static function toObject(string $class, array $input): object
    {
        $arguments = [];

        foreach (Fields::of($class) as $field) {
            if (\array_key_exists($field->name, $input)) {
                $arguments[$field->name] = self::convert($field, $input[$field->name]);
            }
        }

        return new ReflectionClass($class)->newInstanceArgs($arguments);
    }

    private static function convert(Field $field, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        if ($field->isEnum() && (\is_int($value) || \is_string($value))) {
            $enum = $field->type;
            \assert(is_subclass_of($enum, BackedEnum::class));

            return $enum::from($value);
        }

        if ($field->isDate() && \is_string($value)) {
            return new DateTimeImmutable($value);
        }

        if ('float' === $field->type && \is_int($value)) {
            return (float) $value;
        }

        return $value;
    }
}
