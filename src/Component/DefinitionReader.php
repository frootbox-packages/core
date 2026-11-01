<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\HasSubfields;

/**
 * Checks block.yaml and view headers against the meta schema and turns
 * them into options, fields and areas.
 */
final readonly class DefinitionReader
{
    /** Keys of block.yaml and layout.yaml. */
    public const array SCHEMA_KEYS = ['title', 'description', 'widget', 'fields', 'areas'];

    /** Keys of the header of views/Default.twig when there is no block.yaml. */
    public const array FULL_KEYS = ['title', 'description', 'widget', 'options', 'fields', 'areas'];

    /** Keys of the header of any other view, including views that override. */
    public const array VARIANT_KEYS = ['title', 'description', 'options'];

    private const string NAME = '/^[a-z][a-zA-Z0-9]*$/';

    private const array ANY_MAP = ['type' => ['object', 'array', 'null']];

    private const array TOP = [
        'title' => ['type' => 'string', 'minLength' => 1],
        'description' => ['type' => 'string'],
        'widget' => ['type' => 'boolean'],
        'options' => self::ANY_MAP,
        'fields' => self::ANY_MAP,
        'areas' => self::ANY_MAP,
    ];

    private const array OPTION = [
        'type' => ['enum' => Option::TYPES],
        'label' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'default' => ['type' => ['string', 'number', 'boolean']],
        'choices' => self::ANY_MAP,
        'min' => ['type' => 'number'],
        'max' => ['type' => 'number'],
    ];

    private const array FIELD = [
        'type' => ['type' => 'string'],
        'label' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'optional' => ['type' => 'boolean'],
        'placeholder' => ['type' => 'string'],
    ];

    private const array AREA = [
        'label' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'allow' => ['type' => 'array', 'items' => ['type' => 'string']],
        'min' => ['type' => 'integer', 'minimum' => 0],
        'max' => ['type' => 'integer', 'minimum' => 1],
    ];

    public function __construct(
        private FieldTypes $types,
        private InputValidator $validator = new InputValidator(),
    ) {}

    /**
     * @param array<mixed> $raw
     * @param list<string> $keys    Allowed keys, see the constants
     * @param string       $package Short block names in "allow" belong to it
     */
    public function read(array $raw, array $keys, bool $titleRequired, string $package, string $where, DefinitionErrors $errors): Definition
    {
        // Further problems are reported even if the top level has some.
        $this->check($raw, array_intersect_key(self::TOP, array_flip($keys)), $titleRequired ? ['title'] : [], $where, '', $errors);

        return new Definition(
            self::string($raw['title'] ?? null),
            self::string($raw['description'] ?? null),
            $this->options($raw['options'] ?? null, $where, $errors),
            $this->fields($raw['fields'] ?? null, $where, 'fields', $errors),
            $this->areas($raw['areas'] ?? null, $package, $where, $errors),
            true === ($raw['widget'] ?? false),
        );
    }

    /**
     * @return array<string, Option>
     */
    private function options(mixed $raw, string $where, DefinitionErrors $errors): array
    {
        $options = [];

        foreach ($this->map($raw, $where, 'options', $errors) as $name => $definition) {
            $path = 'options.' . $name;

            if (!$this->check($definition, self::OPTION, ['type'], $where, $path, $errors)) {
                continue;
            }

            $type = self::string($definition['type']) ?? 'text';
            $choices = $definition['choices'] ?? [];

            if ('choice' === $type && (!\is_array($choices) || [] === $choices || !array_all($choices, static fn(mixed $label): bool => \is_string($label)))) {
                $errors->add($where, $path . '.choices', 'must list the values with their labels, e.g. { 400: Standard, 550: High }');

                continue;
            }

            $option = new Option(
                $name,
                $type,
                self::string($definition['label'] ?? null) ?? self::label($name),
                self::defaultFor($type, $definition),
                self::string($definition['description'] ?? null) ?? '',
                'choice' === $type ? self::labels($choices) : [],
                self::number($definition['min'] ?? null),
                self::number($definition['max'] ?? null),
            );

            if (!$option->accepts($option->default)) {
                $errors->add($where, $path . '.default', 'does not fit the option');

                continue;
            }

            $options[$name] = $option;
        }

        return $options;
    }

    /**
     * @return array<string, Field>
     */
    private function fields(mixed $raw, string $where, string $path, DefinitionErrors $errors): array
    {
        $fields = [];

        foreach ($this->map($raw, $where, $path, $errors) as $name => $definition) {
            $at = $path . '.' . $name;
            $typeName = $definition['type'] ?? null;

            if (!\is_string($typeName) || '' === $typeName) {
                $errors->add($where, $at . '.type', 'is missing, e.g. "type: headline"');

                continue;
            }

            if (!$this->types->has($typeName)) {
                $errors->add($where, $at . '.type', \sprintf('there is no field type "%s". Known: %s', $typeName, implode(', ', $this->types->names())));

                continue;
            }

            $type = $this->types->get($typeName);
            $nested = $type instanceof HasSubfields;
            $allowed = [...self::FIELD, ...$type->settings(), ...($nested ? ['fields' => self::ANY_MAP] : [])];

            if (!$this->check($definition, $allowed, ['type'], $where, $at, $errors)) {
                continue;
            }

            $subfields = [];

            if ($nested) {
                if ([] === ($definition['fields'] ?? [])) {
                    $errors->add($where, $at . '.fields', 'is missing: the fields of each entry');

                    continue;
                }

                $subfields = $this->fields($definition['fields'], $where, $at . '.fields', $errors);
            }

            $settings = [];

            foreach (array_diff_key($definition, self::FIELD, ['fields' => true]) as $key => $value) {
                $settings[(string) $key] = $value;
            }

            $fields[$name] = new Field(
                $name,
                $typeName,
                self::string($definition['label'] ?? null) ?? self::label($name),
                self::string($definition['description'] ?? null) ?? '',
                true === ($definition['optional'] ?? false),
                $settings,
                $subfields,
                self::string($definition['placeholder'] ?? null) ?? '',
            );
        }

        return $fields;
    }

    /**
     * @return array<string, Area>
     */
    private function areas(mixed $raw, string $package, string $where, DefinitionErrors $errors): array
    {
        $areas = [];

        foreach ($this->map($raw, $where, 'areas', $errors) as $name => $definition) {
            $path = 'areas.' . $name;
            $this->check($definition, self::AREA, [], $where, $path, $errors);

            $allow = null;

            if (\is_array($definition['allow'] ?? null)) {
                $allow = [];

                foreach ($definition['allow'] as $block) {
                    $block = \is_string($block) ? $block : '';

                    if (!ComponentName::isLocal($block) && !ComponentName::isValid($block)) {
                        $errors->add($where, $path . '.allow', \sprintf('"%s" is not a block name, e.g. Column or frootbox/base/Teaser', $block));

                        continue;
                    }

                    $allow[] = ComponentName::resolve($block, $package);
                }
            }

            $min = $definition['min'] ?? null;
            $max = $definition['max'] ?? null;

            $areas[$name] = new Area(
                $name,
                self::string($definition['label'] ?? null) ?? self::label($name),
                self::string($definition['description'] ?? null) ?? '',
                $allow,
                \is_int($min) ? $min : null,
                \is_int($max) ? $max : null,
            );
        }

        return $areas;
    }

    /**
     * Entries of a map such as "fields", each a map of settings.
     *
     * @return array<string, array<mixed>>
     */
    private function map(mixed $raw, string $where, string $path, DefinitionErrors $errors): array
    {
        if (null === $raw) {
            return [];
        }

        if (!\is_array($raw) || (array_is_list($raw) && [] !== $raw)) {
            $errors->add($where, $path, 'must contain names with their settings');

            return [];
        }

        $entries = [];

        foreach ($raw as $name => $definition) {
            $name = (string) $name;

            if (1 !== preg_match(self::NAME, $name)) {
                $errors->add($where, $path . '.' . $name, 'names are camelCase, e.g. "imageHeight"');

                continue;
            }

            if (null !== $definition && !\is_array($definition)) {
                $errors->add($where, $path . '.' . $name, 'must contain settings such as "type"');

                continue;
            }

            $entries[$name] = $definition ?? [];
        }

        return $entries;
    }

    /**
     * Checks one map against the meta schema: known keys and their types.
     *
     * @param array<mixed>                        $raw
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     */
    private function check(array $raw, array $properties, array $required, string $where, string $path, DefinitionErrors $errors): bool
    {
        $before = $errors->count();
        $unknown = array_diff(array_map(strval(...), array_keys($raw)), array_keys($properties));

        foreach ($unknown as $key) {
            $errors->add($where, ltrim($path . '.' . $key, '.'), \sprintf('unknown key. Allowed: %s', implode(', ', array_keys($properties))));
        }

        $input = [];

        foreach ($raw as $key => $value) {
            $input[(string) $key] = $value;
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        $schema = [] === $required ? $schema : [...$schema, 'required' => $required];

        foreach ($this->validator->errors($schema, $input) as $pointer => $message) {
            $key = str_replace('/', '.', trim($pointer, '/'));
            $errors->add($where, trim($path . '.' . $key, '.'), $message);
        }

        return $errors->count() === $before;
    }

    /**
     * @param array<mixed> $definition
     */
    private static function defaultFor(string $type, array $definition): string|int|float|bool
    {
        $default = $definition['default'] ?? null;

        if (\is_string($default) || \is_int($default) || \is_float($default) || \is_bool($default)) {
            return $default;
        }

        $choices = $definition['choices'] ?? null;

        return match ($type) {
            'choice' => \is_array($choices) && [] !== $choices ? array_key_first($choices) : '',
            'boolean' => false,
            'number' => self::number($definition['min'] ?? null) ?? 0,
            default => '',
        };
    }

    /**
     * "imageHeight" becomes "Image height".
     */
    private static function label(string $name): string
    {
        return ucfirst(strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name)));
    }

    /**
     * @return array<int|string, string>
     */
    private static function labels(mixed $choices): array
    {
        return \is_array($choices) ? array_filter($choices, \is_string(...)) : [];
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    private static function number(mixed $value): int|float|null
    {
        return \is_int($value) || \is_float($value) ? $value : null;
    }
}
