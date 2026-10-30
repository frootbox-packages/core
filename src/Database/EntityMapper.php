<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use BackedEnum;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Creates entities from rows.
 */
final class EntityMapper
{
    /** @var array<class-string, list<array{name: string, column: string, type: string, nullable: bool, optional: bool}>> */
    private static array $fields = [];

    /**
     * @template T of Entity
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $row
     *
     * @return T
     */
    public static function hydrate(string $class, array $row): Entity
    {
        $arguments = [];

        foreach (self::fields($class) as $field) {
            if (!\array_key_exists($field['column'], $row)) {
                if ($field['optional']) {
                    continue;
                }

                throw new RepositoryException(\sprintf('%s needs the column "%s", but the row has none.', $class, $field['column']));
            }

            $arguments[$field['name']] = self::convert($class, $field, $row[$field['column']]);
        }

        return new ReflectionClass($class)->newInstanceArgs($arguments);
    }

    /**
     * @param class-string<Entity> $class
     *
     * @return list<string>
     */
    public static function columns(string $class): array
    {
        return array_column(self::fields($class), 'column');
    }

    /**
     * @param class-string<Entity> $class
     *
     * @return list<array{name: string, column: string, type: string, nullable: bool, optional: bool}>
     */
    private static function fields(string $class): array
    {
        if (isset(self::$fields[$class])) {
            return self::$fields[$class];
        }

        $fields = [];

        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            $fields[] = [
                'name' => $parameter->getName(),
                'column' => Values::column($parameter->getName()),
                'type' => $type instanceof ReflectionNamedType ? $type->getName() : 'mixed',
                'nullable' => null === $type || $type->allowsNull(),
                'optional' => $parameter->isDefaultValueAvailable(),
            ];
        }

        return self::$fields[$class] = $fields;
    }

    /**
     * @param array{name: string, column: string, type: string, nullable: bool, optional: bool} $field
     */
    private static function convert(string $class, array $field, mixed $value): mixed
    {
        if (null === $value) {
            if (!$field['nullable']) {
                throw new RepositoryException(\sprintf('The column "%s" is NULL, but %s::$%s does not allow null.', $field['column'], $class, $field['name']));
            }

            return null;
        }

        $type = $field['type'];

        $converted = match (true) {
            'int' === $type => is_numeric($value) ? (int) $value : null,
            'float' === $type => is_numeric($value) ? (float) $value : null,
            'bool' === $type => \is_bool($value) ? $value : (is_numeric($value) ? 0 !== (int) $value : null),
            'string' === $type => \is_scalar($value) ? (string) $value : null,
            'array' === $type => \is_array($value) ? $value : (\is_string($value) ? json_decode($value, true, flags: \JSON_THROW_ON_ERROR) : null),
            \in_array($type, [DateTimeImmutable::class, \DateTimeInterface::class], true) => \is_string($value) ? new DateTimeImmutable($value) : null,
            is_subclass_of($type, BackedEnum::class) => self::enum($type, $value),
            default => $value,
        };

        if (null === $converted) {
            throw new RepositoryException(\sprintf('The column "%s" cannot be converted to %s for %s.', $field['column'], $type, $class));
        }

        return $converted;
    }

    /**
     * @param class-string<BackedEnum> $enum
     */
    private static function enum(string $enum, mixed $value): ?BackedEnum
    {
        if (!\is_int($value) && !\is_string($value)) {
            return null;
        }

        $intBacked = \is_int($enum::cases()[0]->value ?? null);

        return $enum::tryFrom($intBacked && is_numeric($value) ? (int) $value : (string) $value);
    }
}
