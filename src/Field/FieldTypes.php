<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Component\ContentValidator;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * The registered field types by name. Each type is created once, through
 * the container, so it can ask for services.
 */
final class FieldTypes
{
    /** @var array<string, FieldType> */
    private array $instances = [];

    /**
     * @param array<string, class-string<FieldType>> $classes
     */
    public function __construct(
        private readonly array $classes,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * The name from #[AsFieldType], checked.
     *
     * @param class-string $class
     */
    public static function nameOf(string $class): string
    {
        if (!is_subclass_of($class, FieldType::class)) {
            throw new DefinitionException(\sprintf('%s must implement %s.', $class, FieldType::class));
        }

        $attribute = new ReflectionClass($class)->getAttributes(AsFieldType::class)[0] ?? null;

        if (null === $attribute) {
            throw new DefinitionException(\sprintf('%s needs #[AsFieldType(\'name\')].', $class));
        }

        $name = $attribute->newInstance()->name;

        if (!FieldTypeName::isValid($name)) {
            throw new DefinitionException(\sprintf('The field type name "%s" of %s is invalid. Use lower case with dashes, e.g. "price", and in an extension its package in front: "acme/shop/price".', $name, $class));
        }

        return $name;
    }

    public function has(string $name): bool
    {
        return isset($this->classes[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->classes);
    }

    public function get(string $name): FieldType
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $class = $this->classes[$name] ?? throw new DefinitionException(\sprintf('There is no field type "%s".', $name));
        $type = $this->container->get($class);

        if (!$type instanceof FieldType) {
            throw new DefinitionException(\sprintf('%s must implement %s.', $class, FieldType::class));
        }

        return $this->instances[$name] = $type;
    }

    /**
     * JSON schema of an object with these fields, e.g. the content of a
     * block or one entry of a list.
     *
     * @param array<string, Field> $fields
     *
     * @return array<string, mixed>
     */
    public function schemaFor(array $fields): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $name => $field) {
            $properties[$name] = $this->get($field->type)->valueSchema($field);

            if (!$field->optional) {
                $required[] = $name;
            }
        }

        // An empty "properties" would turn into a JSON list, which is no schema.
        $schema = ['type' => 'object', ...([] === $properties ? [] : ['properties' => $properties]), 'additionalProperties' => false];

        return [] === $required ? $schema : [...$schema, 'required' => $required];
    }

    /**
     * The schema for publishing these values (decision 0144): entries of
     * lists that are still blank are left out of the check, unless the
     * list needs them to reach its "min". Then the first blank ones are
     * checked, so their fields can be marked.
     *
     * @param array<string, Field> $fields
     * @param array<mixed>         $values
     *
     * @return array<string, mixed>
     */
    public function schemaForPublishing(array $fields, array $values): array
    {
        $schema = $this->schemaFor($fields);
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($fields as $name => $field) {
            $entries = $values[$name] ?? null;

            if ([] === $field->fields || !\is_array($entries) || !array_is_list($entries) || !\is_array($properties[$name] ?? null)) {
                continue;
            }

            $items = $this->schemaFor($field->fields);
            $missing = ($field->intSetting('min') ?? 0) - \count(array_filter($entries, static fn(mixed $entry): bool => !Blank::is($entry)));
            $prefix = [];

            foreach ($entries as $entry) {
                if (Blank::is($entry) && $missing-- <= 0) {
                    $prefix[] = ContentValidator::withoutMinimums($items);
                } else {
                    $prefix[] = \is_array($entry) ? $this->schemaForPublishing($field->fields, $entry) : $items;
                }
            }

            $properties[$name] = [...$properties[$name], 'prefixItems' => $prefix];
        }

        return [] === $properties ? $schema : [...$schema, 'properties' => $properties];
    }

    /**
     * Checks of the field types beyond the schema, for an object with these fields.
     *
     * @param array<string, Field> $fields
     * @param array<mixed>         $values
     *
     * @return array<string, string> Message by JSON pointer, e.g. "/button/url"
     */
    public function validate(array $fields, array $values): array
    {
        $errors = [];

        foreach ($fields as $name => $field) {
            if (!\array_key_exists($name, $values) || null === $values[$name]) {
                continue;
            }

            foreach ($this->get($field->type)->validate($values[$name], $field) as $pointer => $message) {
                $errors['/' . $name . $pointer] = $message;
            }
        }

        return $errors;
    }

    /**
     * Ids of the media used in an object with these fields.
     *
     * @param array<string, Field> $fields
     * @param array<mixed>         $values
     *
     * @return list<string>
     */
    public function media(array $fields, array $values): array
    {
        $ids = [];

        foreach ($fields as $name => $field) {
            $type = $this->get($field->type);

            if ($type instanceof ReferencesMedia && null !== ($values[$name] ?? null)) {
                array_push($ids, ...$type->media($values[$name], $field));
            }
        }

        return $ids;
    }
}
