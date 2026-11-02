<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Schema;

use BackedEnum;

/**
 * Builds the JSON schema of an action or query from its constructor.
 */
final class SchemaFactory
{
    /**
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    public static function forClass(string $class): array
    {
        $properties = [];
        $required = [];

        foreach (Fields::of($class) as $field) {
            $properties[$field->name] = self::property($field);

            if (!$field->optional) {
                $required[] = $field->name;
            }
        }

        $schema = ['type' => 'object'];

        if ([] !== $properties) {
            $schema['properties'] = $properties;
        }

        if ([] !== $required) {
            $schema['required'] = $required;
        }

        $schema['additionalProperties'] = false;

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function property(Field $field): array
    {
        $schema = self::type($field);

        if ($field->nullable && 'mixed' !== $field->type) {
            if (isset($schema['enum']) && \is_array($schema['enum'])) {
                $schema['enum'][] = null;
            } elseif (isset($schema['type'])) {
                $schema['type'] = [...(array) $schema['type'], 'null'];
            }
        }

        if (null !== $title = $field->attribute(Title::class)) {
            $schema['title'] = $title->text;
        }

        if (null !== $description = $field->attribute(Description::class)) {
            $schema['description'] = $description->text;
        }

        if (null !== $length = $field->attribute(Length::class)) {
            $schema += array_filter(['minLength' => $length->min, 'maxLength' => $length->max], static fn(?int $value): bool => null !== $value);
        }

        if (null !== $range = $field->attribute(Range::class)) {
            $schema += array_filter(['minimum' => $range->min, 'maximum' => $range->max], static fn(int|float|null $value): bool => null !== $value);
        }

        if (null !== $pattern = $field->attribute(Pattern::class)) {
            $schema['pattern'] = $pattern->pattern;
        }

        if (null !== $field->attribute(Email::class)) {
            $schema['format'] = 'email';
        }

        if ($field->isSensitive()) {
            $schema['writeOnly'] = true;
        }

        if ($field->optional && (!\is_object($field->default) || $field->default instanceof BackedEnum)) {
            $schema['default'] = $field->default instanceof BackedEnum ? $field->default->value : $field->default;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function type(Field $field): array
    {
        if ($field->isEnum()) {
            $enum = $field->type;
            \assert(is_subclass_of($enum, BackedEnum::class));

            return ['enum' => array_map(static fn(BackedEnum $case): int|string => $case->value, $enum::cases())];
        }

        if ($field->isDate()) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        return match ($field->type) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => null !== ($list = $field->attribute(ListOf::class))
                ? ['type' => 'array', 'items' => ['type' => $list->type]]
                : ['type' => ['array', 'object']],
            default => [],
        };
    }
}
