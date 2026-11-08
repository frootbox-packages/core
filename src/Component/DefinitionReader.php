<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\HasSubfields;
use Frootbox\Core\Field\ObjectOnly;

/**
 * Checks block.yaml and view headers against the meta schema and turns
 * them into options, fields and areas.
 */
final readonly class DefinitionReader
{
    /** Keys of block.yaml and layout.yaml. */
    public const array SCHEMA_KEYS = ['title', 'description', 'group', 'keywords', 'widget', 'fields', 'areas', 'examples', 'query', 'plugin'];

    /** Keys of the header of views/Default.twig when there is no block.yaml. */
    public const array FULL_KEYS = ['title', 'description', 'group', 'keywords', 'widget', 'options', 'fields', 'areas', 'examples', 'query', 'plugin', 'object'];

    /** Keys of the header of any other view, including views that override. */
    public const array VARIANT_KEYS = ['title', 'description', 'options', 'object'];

    private const string NAME = '/^[a-z][a-zA-Z0-9]*$/';

    private const array ANY_MAP = ['type' => ['object', 'array', 'null']];

    private const array TOP = [
        'title' => ['type' => 'string', 'minLength' => 1],
        'description' => ['type' => 'string'],
        // One group of the block picker, e.g. "teaser" (decision 0148).
        'group' => ['type' => 'string', 'pattern' => BlockGroup::NAME],
        'keywords' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]],
        'widget' => ['type' => 'boolean'],
        'plugin' => ['type' => 'boolean'],
        'options' => self::ANY_MAP,
        'fields' => self::ANY_MAP,
        'areas' => self::ANY_MAP,
        // Content for the design page, each like a block on a page (decisions 0205, 0206).
        'examples' => ['type' => 'array'],
        // Query parameters the block reads, e.g. [page, category] (decision 0219).
        'query' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z][a-zA-Z0-9]*$'], 'uniqueItems' => true],
        // Further fields of the objects the view shows (decisions 0234, 0240).
        'object' => ['type' => ['object', 'array']],
    ];

    /** Keys below "object" (decision 0240). */
    private const array OBJECT = [
        'type' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*/[a-z][a-z0-9-]*$'],
        'fields' => self::ANY_MAP,
    ];

    /** Keys of an example (decision 0206). */
    private const array EXAMPLE = ['variant', 'fields', 'areas', 'options'];

    private const array OPTION = [
        'type' => ['enum' => Option::TYPES],
        'label' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'default' => ['type' => ['string', 'number', 'boolean']],
        'choices' => self::ANY_MAP,
        'source' => ['type' => 'string', 'pattern' => '^(?:[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*/)?[a-z][a-z0-9-]*(?:\\.[a-z][a-z0-9-]*)+$'],
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

    /** Keys of a field below "object" in addition (decisions 0240, 0250). */
    private const array OBJECT_FIELD = [
        // A text can hold one value for all languages and the other way round.
        'translatable' => ['type' => 'boolean'],
        // Labels by language.
        'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 200]],
    ];

    private const array AREA = [
        'label' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'allow' => ['type' => 'array', 'items' => ['type' => 'string']],
        'min' => ['type' => 'integer', 'minimum' => 0],
        'max' => ['type' => 'integer', 'minimum' => 1],
        'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
    ];

    /**
     * The meta schema by part, for the reference: each key with its JSON
     * schema. "field" holds the keys of every field, "fields" for types
     * with entries such as list, and those only below "object".
     *
     * @internal
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function schema(): array
    {
        $example = [];

        foreach (self::EXAMPLE as $key) {
            $example[$key] = 'variant' === $key ? ['type' => 'string'] : self::ANY_MAP;
        }

        return [
            'top' => self::TOP,
            'option' => self::OPTION,
            'field' => [...self::FIELD, 'fields' => self::ANY_MAP, ...self::OBJECT_FIELD],
            'area' => self::AREA,
            'object' => self::OBJECT,
            'example' => $example,
        ];
    }

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
            self::string($raw['group'] ?? null),
            \is_array($raw['keywords'] ?? null) ? array_values(array_filter($raw['keywords'], \is_string(...))) : [],
            $this->examples($raw['examples'] ?? null, $where, $errors),
            \is_array($raw['query'] ?? null) ? array_values(array_filter($raw['query'], static fn(mixed $name): bool => \is_string($name) && 1 === preg_match(self::NAME, $name))) : [],
            true === ($raw['plugin'] ?? false),
            $this->object($raw['object'] ?? null, $where, $errors),
        );
    }

    /**
     * Fields of objects alone, e.g. those an extension adds to a type
     * (decision 0240).
     *
     * @return array<string, Field>
     */
    public function objectFields(mixed $raw, string $where, DefinitionErrors $errors): array
    {
        return $this->fields($raw, $where, 'object.fields', $errors, true);
    }

    private function object(mixed $raw, string $where, DefinitionErrors $errors): ?ObjectDefinition
    {
        if (!\is_array($raw) || !$this->check($raw, self::OBJECT, ['fields'], $where, 'object', $errors)) {
            return null;
        }

        return new ObjectDefinition(self::string($raw['type'] ?? null), $this->objectFields($raw['fields'], $where, $errors));
    }

    /**
     * The examples of a block for the design page: fields, areas and
     * options as on a page, for one variant or all (decisions 0205,
     * 0206). Here only their shape; the ComponentLoader checks the names.
     *
     * @return list<array<string, mixed>>
     */
    private function examples(mixed $raw, string $where, DefinitionErrors $errors): array
    {
        if (null === $raw) {
            return [];
        }

        if (!\is_array($raw) || !array_is_list($raw)) {
            $errors->add($where, 'examples', 'must be a list, each with fields, areas, options or a variant');

            return [];
        }

        $examples = [];

        foreach ($raw as $index => $entry) {
            $path = 'examples.' . $index;

            if (!\is_array($entry) || (array_is_list($entry) && [] !== $entry)) {
                $errors->add($where, $path, 'must be a map with fields, areas, options or a variant');

                continue;
            }

            $example = [];

            foreach ($entry as $key => $value) {
                if (!\in_array($key, self::EXAMPLE, true)) {
                    $errors->add($where, $path . '.' . $key, 'unknown key. Allowed: ' . implode(', ', self::EXAMPLE));
                } elseif ('variant' === $key ? !\is_string($value) : !\is_array($value) || (array_is_list($value) && [] !== $value)) {
                    $errors->add($where, $path . '.' . $key, 'variant' === $key ? 'must be the name of a variant' : 'must be a map by name');
                } else {
                    $example[$key] = $value;
                }
            }

            $examples[] = $example;
        }

        return $examples;
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
            $source = self::string($definition['source'] ?? null);

            if (null !== $source && 'choice' !== $type) {
                $errors->add($where, $path . '.source', 'is only for options of type choice');

                continue;
            }

            if ('choice' === $type && null === $source && (!\is_array($choices) || [] === $choices || !array_all($choices, static fn(mixed $label): bool => \is_string($label)))) {
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
                $source,
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
    private function fields(mixed $raw, string $where, string $path, DefinitionErrors $errors, bool $object = false): array
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

            if (!$object && $type instanceof ObjectOnly) {
                $errors->add($where, $at . '.type', \sprintf('"%s" is for fields of objects under "object.fields"; a block uses an option for it', $typeName));

                continue;
            }

            $nested = $type instanceof HasSubfields;
            $allowed = [...self::FIELD, ...$type->settings(), ...($nested ? ['fields' => self::ANY_MAP] : []), ...($object && 'object.fields' === $path ? self::OBJECT_FIELD : [])];

            if (!$this->check($definition, $allowed, ['type'], $where, $at, $errors)) {
                continue;
            }

            $subfields = [];

            if ($nested) {
                if ([] === ($definition['fields'] ?? [])) {
                    $errors->add($where, $at . '.fields', 'is missing: the fields of each entry');

                    continue;
                }

                // Every entry keeps its id there (decision 0344).
                if (\is_array($definition['fields']) && \array_key_exists('id', $definition['fields'])) {
                    $errors->add($where, $at . '.fields.id', 'is the id of each entry; name the field differently');

                    continue;
                }

                $subfields = $this->fields($definition['fields'], $where, $at . '.fields', $errors, $object);
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
                \is_int($definition['level'] ?? null) ? $definition['level'] : null,
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
