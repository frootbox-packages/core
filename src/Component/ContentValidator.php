<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Field\FieldTypes;

/**
 * Checks the content of a layout with its blocks against their
 * definitions and gives every block an id (decision 0100):
 *
 *     {"options": {}, "fields": {"heading": "Hello"},
 *      "areas": {"main": [{"id": "k3x9q2mf", "block": "acme/website/Hero", "fields": {}}]}}
 *
 * A draft may be unfinished: required fields and the minimum number of
 * blocks in an area are only checked when $complete is true, e.g. on
 * publishing.
 */
final readonly class ContentValidator
{
    public const string ID_PATTERN = '^[a-z0-9]{8}$';

    private const string ID_CHARACTERS = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private const array BODY_KEYS = ['options', 'fields', 'areas'];

    private const array BLOCK_KEYS = ['id', 'block', 'variant', ...self::BODY_KEYS];

    public function __construct(
        private Components $components,
        private FieldTypes $types,
        private InputValidator $validator,
    ) {}

    /**
     * @return array{array<string, mixed>, array<string, string>} The content with block ids, and messages by JSON pointer
     */
    public function check(Component $layout, ?string $variant, mixed $content, bool $complete): array
    {
        $errors = [];
        $ids = [];

        if (!self::isMap($content)) {
            return [[], ['/' => 'must be an object with "options", "fields" and "areas".']];
        }

        $checked = $this->body($layout, $variant, $content, '', $complete, $errors, $ids, self::BODY_KEYS);

        return [$checked, $errors];
    }

    /**
     * @param array<mixed>          $data
     * @param array<string, string> $errors
     * @param array<string, true>   $ids
     * @param list<string>          $keys
     *
     * @return array<string, mixed>
     */
    private function body(Component $component, ?string $variant, array $data, string $pointer, bool $complete, array &$errors, array &$ids, array $keys): array
    {
        $unknown = array_diff(array_map(strval(...), array_keys($data)), $keys);

        if ([] !== $unknown) {
            $errors['' === $pointer ? '/' : $pointer] = \sprintf('Unknown keys: %s. Allowed: %s.', implode(', ', $unknown), implode(', ', $keys));
        }

        $checked = [];

        if (null !== $variant && !isset($component->variants[$variant])) {
            $errors[$pointer . '/variant'] = \sprintf('%s has no variant "%s". Variants: %s.', $component->name, $variant, implode(', ', array_keys($component->variants)));
        }

        $options = $this->options($component->variant($variant), $data['options'] ?? [], $pointer . '/options', $errors);
        $fields = $this->fields($component, $data['fields'] ?? [], $pointer . '/fields', $complete, $errors);
        $areas = $this->areas($component, $data['areas'] ?? [], $pointer . '/areas', $complete, $errors, $ids);

        foreach (['options' => $options, 'fields' => $fields, 'areas' => $areas] as $key => $value) {
            if ([] !== $value) {
                $checked[$key] = $value;
            }
        }

        return $checked;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function options(Variant $variant, mixed $options, string $pointer, array &$errors): array
    {
        if (!self::isMap($options)) {
            $errors[$pointer] = 'must be an object.';

            return [];
        }

        $checked = [];

        foreach ($options as $name => $value) {
            $name = (string) $name;
            $option = $variant->options[$name] ?? null;

            if (null === $option) {
                $errors[$pointer . '/' . $name] = \sprintf('is not an option of this variant. Options: %s.', [] === $variant->options ? 'none' : implode(', ', array_keys($variant->options)));
            } elseif (!$option->accepts($value)) {
                $errors[$pointer . '/' . $name] = 'choice' === $option->type
                    ? \sprintf('must be one of %s.', implode(', ', array_keys($option->choices)))
                    : \sprintf('must be a valid %s.', $option->type);
            } else {
                $checked[$name] = $value;
            }
        }

        return $checked;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function fields(Component $component, mixed $fields, string $pointer, bool $complete, array &$errors): array
    {
        if (!self::isMap($fields)) {
            $errors[$pointer] = 'must be an object.';

            return [];
        }

        $values = [];

        foreach ($fields as $name => $value) {
            $values[(string) $name] = $value;
        }

        $schema = $this->types->schemaFor($component->fields);
        $found = $this->validator->errors($complete ? $schema : self::withoutMinimums($schema), $values)
            + $this->types->validate($component->fields, $values);

        foreach ($found as $path => $message) {
            $errors[$pointer . ('/' === $path ? '' : $path)] = $message;
        }

        return $values;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, true>   $ids
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function areas(Component $component, mixed $areas, string $pointer, bool $complete, array &$errors, array &$ids): array
    {
        if (!self::isMap($areas)) {
            $errors[$pointer] = 'must be an object with a list of blocks per area.';

            return [];
        }

        foreach (array_keys($areas) as $name) {
            if (!isset($component->areas[(string) $name])) {
                $errors[$pointer . '/' . $name] = \sprintf('%s has no area "%s". Areas: %s.', $component->name, $name, [] === $component->areas ? 'none' : implode(', ', array_keys($component->areas)));
            }
        }

        $checked = [];

        foreach ($component->areas as $name => $area) {
            $blocks = $areas[$name] ?? [];

            if (!\is_array($blocks) || !array_is_list($blocks)) {
                $errors[$pointer . '/' . $name] = 'must be a list of blocks.';

                continue;
            }

            if (null !== $area->max && \count($blocks) > $area->max) {
                $errors[$pointer . '/' . $name] = \sprintf('may contain at most %d blocks.', $area->max);
            }

            if ($complete && null !== $area->min && \count($blocks) < $area->min) {
                $errors[$pointer . '/' . $name] = \sprintf('needs at least %d blocks.', $area->min);
            }

            $list = [];

            foreach ($blocks as $index => $block) {
                $checkedBlock = $this->block($area, $block, $pointer . '/' . $name . '/' . $index, $complete, $errors, $ids);

                if (null !== $checkedBlock) {
                    $list[] = $checkedBlock;
                }
            }

            if ([] !== $list) {
                $checked[$name] = $list;
            }
        }

        return $checked;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, true>   $ids
     *
     * @return array<string, mixed>|null
     */
    private function block(Area $area, mixed $block, string $pointer, bool $complete, array &$errors, array &$ids): ?array
    {
        if (!self::isMap($block) || [] === $block) {
            $errors[$pointer] = 'must be a block like {"block": "acme/website/Teaser", "fields": {}}.';

            return null;
        }

        $name = $block['block'] ?? null;

        if (!\is_string($name)) {
            $errors[$pointer . '/block'] = 'is required: the full name of the block, e.g. "acme/website/Teaser".';

            return null;
        }

        $component = $this->components->block($name);

        if (null === $component) {
            $errors[$pointer . '/block'] = \sprintf('there is no block "%s".', $name);

            return null;
        }

        if (!$area->allows($name, $component->widget)) {
            $errors[$pointer . '/block'] = null === $area->allow
                ? \sprintf('%s is a widget and only goes into areas that allow it by name, e.g. the columns of a section.', $name)
                : \sprintf('%s is not allowed in the area "%s". Allowed: %s.', $name, $area->name, implode(', ', $area->allow));
        }

        $variant = $block['variant'] ?? null;

        if (null !== $variant && !\is_string($variant)) {
            $errors[$pointer . '/variant'] = 'must be the name of a variant.';
            $variant = null;
        }

        $id = $this->id($block['id'] ?? null, $pointer . '/id', $errors, $ids);
        $body = $this->body($component, $variant, $block, $pointer, $complete, $errors, $ids, self::BLOCK_KEYS);

        return ['id' => $id, 'block' => $name, ...(null === $variant ? [] : ['variant' => $variant]), ...$body];
    }

    /**
     * The given id if it is valid and unused, otherwise a new one.
     *
     * @param array<string, string> $errors
     * @param array<string, true>   $ids
     */
    private function id(mixed $id, string $pointer, array &$errors, array &$ids): string
    {
        if (null !== $id) {
            if (!\is_string($id) || 1 !== preg_match('/' . self::ID_PATTERN . '/', $id)) {
                $errors[$pointer] = 'must be 8 characters a-z and 0-9, or be left out for a new block.';
            } elseif (isset($ids[$id])) {
                $errors[$pointer] = \sprintf('"%s" is used by another block of this content.', $id);
            } else {
                return $this->claim($id, $ids);
            }
        }

        do {
            $new = '';

            for ($i = 0; $i < 8; ++$i) {
                $new .= self::ID_CHARACTERS[random_int(0, 35)];
            }
        } while (isset($ids[$new]));

        return $this->claim($new, $ids);
    }

    /**
     * @param array<string, true> $ids
     */
    private function claim(string $id, array &$ids): string
    {
        $ids[$id] = true;

        return $id;
    }

    /**
     * The schema without "required" and "minItems", for drafts. Only
     * schemas are changed, never names of fields.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function withoutMinimums(array $schema): array
    {
        unset($schema['required'], $schema['minItems']);

        if (\is_array($schema['properties'] ?? null)) {
            $schema['properties'] = array_map(self::nested(...), $schema['properties']);
        }

        if (\array_key_exists('items', $schema)) {
            $schema['items'] = self::nested($schema['items']);
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $key) {
            if (\is_array($schema[$key] ?? null)) {
                $schema[$key] = array_map(self::nested(...), $schema[$key]);
            }
        }

        return $schema;
    }

    private static function nested(mixed $schema): mixed
    {
        if (!\is_array($schema)) {
            return $schema;
        }

        $typed = [];

        foreach ($schema as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return self::withoutMinimums($typed);
    }

    /**
     * An object in JSON: an array with names as keys, or an empty one.
     *
     * @phpstan-assert-if-true array<mixed> $value
     */
    private static function isMap(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }
}
