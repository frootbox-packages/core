<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Schema;

use ReflectionParameter;

/**
 * One input field of an action or query: a promoted constructor parameter.
 */
final readonly class Field
{
    /**
     * @param string $type "string", "int", "float", "bool", "array", "mixed" or a class name
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public bool $optional,
        public mixed $default,
        private ReflectionParameter $parameter,
    ) {}

    /**
     * @template A of object
     *
     * @param class-string<A> $class
     *
     * @return A|null
     */
    public function attribute(string $class): ?object
    {
        foreach ($this->parameter->getAttributes($class) as $attribute) {
            return $attribute->newInstance();
        }

        return null;
    }

    public function isSensitive(): bool
    {
        return null !== $this->attribute(Sensitive::class);
    }

    public function isEnum(): bool
    {
        return is_subclass_of($this->type, \BackedEnum::class);
    }

    public function isDate(): bool
    {
        return \in_array($this->type, [\DateTimeImmutable::class, \DateTimeInterface::class], true);
    }
}
