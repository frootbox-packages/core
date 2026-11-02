<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Schema;

use Frootbox\Core\Action\DefinitionException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Reads the input fields of an action or query from its constructor.
 *
 * Every parameter must be a public promoted property with one type (or
 * that type and null): string, int, float, bool, array, mixed, a backed
 * enum or DateTimeImmutable.
 */
final class Fields
{
    private const array BUILTIN = ['string', 'int', 'float', 'bool', 'array', 'mixed'];

    /** @var array<class-string, list<Field>> */
    private static array $cache = [];

    /**
     * @param class-string $class
     *
     * @return list<Field>
     */
    public static function of(string $class): array
    {
        return self::$cache[$class] ??= self::read($class);
    }

    /**
     * @param class-string $class
     *
     * @return list<Field>
     */
    private static function read(string $class): array
    {
        $constructor = new ReflectionClass($class)->getConstructor();

        if (null === $constructor) {
            return [];
        }

        return array_map(static fn(ReflectionParameter $parameter): Field => self::field($class, $parameter), $constructor->getParameters());
    }

    /**
     * @param class-string $class
     */
    private static function field(string $class, ReflectionParameter $parameter): Field
    {
        $name = $parameter->getName();
        $where = \sprintf('%s::$%s', $class, $name);

        if (!$parameter->isPromoted() || !new \ReflectionProperty($class, $name)->isPublic()) {
            throw new DefinitionException(\sprintf('%s must be a public promoted constructor property, so the input can be read back.', $where));
        }

        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            throw new DefinitionException(\sprintf('%s needs exactly one type (optionally nullable).', $where));
        }

        $typeName = $type->getName();

        if (!\in_array($typeName, self::BUILTIN, true) && !is_subclass_of($typeName, \BackedEnum::class) && !\in_array($typeName, [\DateTimeImmutable::class, \DateTimeInterface::class], true)) {
            throw new DefinitionException(\sprintf('%s has the unsupported type %s. Use string, int, float, bool, array, a backed enum or DateTimeImmutable.', $where, $typeName));
        }

        $optional = $parameter->isDefaultValueAvailable();

        return new Field($name, $typeName, $type->allowsNull(), $optional, $optional ? $parameter->getDefaultValue() : null, $parameter);
    }
}
