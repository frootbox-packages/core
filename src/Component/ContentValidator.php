<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Support\Message;

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
 *
 * What no definition knows any more, because an extension was removed or
 * updated, is orphaned (decision 0276): a block whose name is gone, the
 * view of a plugin, or a variant, option, field or area a block or the
 * layout no longer has. It is kept as it is and no error if it is
 * unchanged from the stored content. New or changed, it is an error.
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
     * @param mixed $stored The content as it is stored, to keep what is orphaned in it
     *
     * @return array{array<string, mixed>, array<string, string|Message>} The content with block ids, and messages by JSON pointer
     */
    public function check(Component $layout, ?string $variant, mixed $content, bool $complete, mixed $stored = null): array
    {
        $errors = [];
        $ids = [];

        if (!self::isMap($content)) {
            return [[], ['/' => 'must be an object with "options", "fields" and "areas".']];
        }

        $stored = self::isMap($stored) ? $stored : [];
        $before = self::index($stored);
        $plugin = $content['plugin'] ?? null;
        unset($content['plugin']);
        $checked = $this->body($layout, $variant, $content, '', $complete, $errors, $ids, self::BODY_KEYS, $stored, $before);

        if (null !== $plugin) {
            $view = $this->plugin($plugin, $complete, $errors, $ids, $stored['plugin'] ?? null, $before);

            if (null !== $view) {
                $checked['plugin'] = $view;
            }
        }

        return [$checked, $errors];
    }

    /**
     * @param array<mixed>          $data
     * @param array<string, string|Message> $errors
     * @param array<string, true>   $ids
     * @param list<string>          $keys
     * @param array<mixed>          $stored The same block or layout as stored, or [] for a new one
     * @param array<string, mixed>  $before Stored blocks by id
     *
     * @return array<string, mixed>
     */
    private function body(Component $component, ?string $variant, array $data, string $pointer, bool $complete, array &$errors, array &$ids, array $keys, array $stored, array $before): array
    {
        $unknown = array_diff(array_map(strval(...), array_keys($data)), $keys);

        if ([] !== $unknown) {
            $errors['' === $pointer ? '/' : $pointer] = new Message('Unknown keys: {keys}. Allowed: {allowed}.', ['keys' => implode(', ', $unknown), 'allowed' => implode(', ', $keys)]);
        }

        $checked = [];

        if (null !== $variant && !isset($component->variants[$variant]) && $variant !== ($stored['variant'] ?? null)) {
            $errors[$pointer . '/variant'] = new Message('{component} has no variant "{variant}". Variants: {variants}.', ['component' => $component->name, 'variant' => $variant, 'variants' => implode(', ', array_keys($component->variants))]);
        }

        $options = $this->options($component->variant($variant), $data['options'] ?? [], $pointer . '/options', $errors, self::map($stored['options'] ?? null));
        $fields = $this->fields($component, $data['fields'] ?? [], $pointer . '/fields', $complete, $errors, self::map($stored['fields'] ?? null));
        $areas = $this->areas($component, $data['areas'] ?? [], $pointer . '/areas', $complete, $errors, $ids, self::map($stored['areas'] ?? null), $before);

        foreach (['options' => $options, 'fields' => $fields, 'areas' => $areas] as $key => $value) {
            if ([] !== $value) {
                $checked[$key] = $value;
            }
        }

        return $checked;
    }

    /**
     * @param array<string, string|Message> $errors
     * @param array<string, mixed>  $stored
     *
     * @return array<string, mixed>
     */
    private function options(Variant $variant, mixed $options, string $pointer, array &$errors, array $stored): array
    {
        if (!self::isMap($options)) {
            $errors[$pointer] = 'must be an object.';

            return [];
        }

        $checked = [];

        foreach ($options as $name => $value) {
            $name = (string) $name;
            $option = $variant->options[$name] ?? null;

            if ((null === $option || !$option->accepts($value)) && self::unchanged($stored, $name, $value)) {
                $checked[$name] = $value;
            } elseif (null === $option) {
                $errors[$pointer . '/' . $name] = [] === $variant->options
                    ? new Message('is not an option, this variant has none.')
                    : new Message('is not an option of this variant. Options: {options}.', ['options' => implode(', ', array_keys($variant->options))]);
            } elseif (!$option->accepts($value)) {
                $errors[$pointer . '/' . $name] = 'choice' === $option->type
                    ? new Message('must be one of {choices}.', ['choices' => implode(', ', array_keys($option->choices))])
                    : new Message('must be a valid {type}.', ['type' => $option->type]);
            } else {
                $checked[$name] = $value;
            }
        }

        return $checked;
    }

    /**
     * @param array<string, string|Message> $errors
     * @param array<string, mixed>  $stored
     *
     * @return array<string, mixed>
     */
    private function fields(Component $component, mixed $fields, string $pointer, bool $complete, array &$errors, array $stored): array
    {
        if (!self::isMap($fields)) {
            $errors[$pointer] = 'must be an object.';

            return [];
        }

        $values = [];
        $orphaned = [];

        foreach ($fields as $name => $value) {
            $name = (string) $name;

            if (!isset($component->fields[$name]) && self::unchanged($stored, $name, $value)) {
                $orphaned[$name] = $value;
            } else {
                $values[$name] = $value;
            }
        }

        $schema = $complete ? $this->types->schemaForPublishing($component->fields, $values) : self::withoutMinimums($this->types->schemaFor($component->fields));
        $found = $this->validator->messages($schema, $values)
            + $this->types->validate($component->fields, $values);

        foreach ($found as $path => $message) {
            $errors[$pointer . ('/' === $path ? '' : $path)] = $message;
        }

        return $values + $orphaned;
    }

    /**
     * @param array<string, string|Message> $errors
     * @param array<string, true>   $ids
     * @param array<string, mixed>  $stored
     * @param array<string, mixed>  $before
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function areas(Component $component, mixed $areas, string $pointer, bool $complete, array &$errors, array &$ids, array $stored, array $before): array
    {
        if (!self::isMap($areas)) {
            $errors[$pointer] = 'must be an object with a list of blocks per area.';

            return [];
        }

        $checked = [];
        $orphaned = [];

        foreach ($areas as $name => $blocks) {
            $name = (string) $name;

            if (isset($component->areas[$name])) {
                continue;
            }

            if (\is_array($blocks) && self::unchanged($stored, $name, $blocks) && $this->claimAll($blocks, $pointer . '/' . $name, $errors, $ids)) {
                /** @var list<array<string, mixed>> $blocks */
                $orphaned[$name] = $blocks;
            } else {
                $errors[$pointer . '/' . $name] = [] === $component->areas
                    ? new Message('{component} has no areas.', ['component' => $component->name])
                    : new Message('{component} has no area "{area}". Areas: {areas}.', ['component' => $component->name, 'area' => $name, 'areas' => implode(', ', array_keys($component->areas))]);
            }
        }

        foreach ($component->areas as $name => $area) {
            $blocks = $areas[$name] ?? [];

            if (!\is_array($blocks) || !array_is_list($blocks)) {
                $errors[$pointer . '/' . $name] = 'must be a list of blocks.';

                continue;
            }

            if (null !== $area->max && \count($blocks) > $area->max) {
                $errors[$pointer . '/' . $name] = new Message('may contain at most {max} blocks.', ['max' => $area->max]);
            }

            if ($complete && null !== $area->min && \count($blocks) < $area->min) {
                $errors[$pointer . '/' . $name] = new Message('needs at least {min} blocks.', ['min' => $area->min]);
            }

            $list = [];

            foreach ($blocks as $index => $block) {
                $checkedBlock = $this->block($area, $block, $pointer . '/' . $name . '/' . $index, $complete, $errors, $ids, $before);

                if (null !== $checkedBlock) {
                    $list[] = $checkedBlock;
                }
            }

            if ([] !== $list) {
                $checked[$name] = $list;
            }
        }

        return $checked + $orphaned;
    }

    /**
     * @param array<string, string|Message> $errors
     * @param array<string, true>   $ids
     * @param array<string, mixed>  $before
     *
     * @return array<string, mixed>|null
     */
    private function block(Area $area, mixed $block, string $pointer, bool $complete, array &$errors, array &$ids, array $before): ?array
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
        $stored = self::map($before[\is_string($block['id'] ?? null) ? $block['id'] : ''] ?? null);
        $stored = ($stored['block'] ?? null) === $name ? $stored : [];

        if (null === $component) {
            if (self::same($stored, $block) && $this->claimAll([$block], $pointer, $errors, $ids)) {
                return $stored;
            }

            $errors[$pointer . '/block'] = new Message('there is no block "{block}".', ['block' => $name]);

            return null;
        }

        if ($component->plugin) {
            $errors[$pointer . '/block'] = new Message('{block} is the view of a plugin. It goes into no area, the page shows it when it shows a space of the plugin.', ['block' => $name]);
        }

        if (!$area->allows($name, $component->widget)) {
            $errors[$pointer . '/block'] = null === $area->allow
                ? new Message('{block} is a widget and only goes into areas that allow it by name, e.g. the columns of a section.', ['block' => $name])
                : new Message('{block} is not allowed in the area "{area}". Allowed: {allowed}.', ['block' => $name, 'area' => $area->name, 'allowed' => implode(', ', $area->allow)]);
        }

        $variant = $block['variant'] ?? null;

        if (null !== $variant && !\is_string($variant)) {
            $errors[$pointer . '/variant'] = 'must be the name of a variant.';
            $variant = null;
        }

        $id = $this->id($block['id'] ?? null, $pointer . '/id', $errors, $ids);
        $body = $this->body($component, $variant, $block, $pointer, $complete, $errors, $ids, self::BLOCK_KEYS, $stored, $before);

        return ['id' => $id, 'block' => $name, ...(null === $variant ? [] : ['variant' => $variant]), ...$body];
    }

    /**
     * The view of the plugin a page shows (decision 0232): a block with
     * "plugin: true", outside the areas.
     *
     * @param array<string, string|Message> $errors
     * @param array<string, true>   $ids
     * @param array<string, mixed>  $before
     *
     * @return array<string, mixed>|null
     */
    private function plugin(mixed $block, bool $complete, array &$errors, array &$ids, mixed $stored, array $before): ?array
    {
        $name = self::isMap($block) ? $block['block'] ?? null : null;
        $component = \is_string($name) ? $this->components->block($name) : null;
        $stored = self::map($stored);

        if (null === $component && \is_array($block) && [] !== $stored && self::same($stored, $block) && $this->claimAll([$block], '/plugin', $errors, $ids)) {
            return $stored;
        }

        $stored = ($stored['block'] ?? null) === $name ? $stored : [];

        if (!\is_array($block) || !\is_string($name) || null === $component || !$component->plugin) {
            $errors['/plugin'] = 'must be the view of a plugin, e.g. {"block": "acme/news/ArticleList"}.';

            return null;
        }

        $variant = \is_string($block['variant'] ?? null) ? $block['variant'] : null;
        $id = $this->id($block['id'] ?? null, '/plugin/id', $errors, $ids);
        $body = $this->body($component, $variant, $block, '/plugin', $complete, $errors, $ids, self::BLOCK_KEYS, $stored, $before);

        return ['id' => $id, 'block' => $name, ...(null === $variant ? [] : ['variant' => $variant]), ...$body];
    }

    /**
     * The given id if it is valid and unused, otherwise a new one.
     *
     * @param array<string, string|Message> $errors
     * @param array<string, true>   $ids
     */
    private function id(mixed $id, string $pointer, array &$errors, array &$ids): string
    {
        if (null !== $id) {
            if (!\is_string($id) || 1 !== preg_match('/' . self::ID_PATTERN . '/', $id)) {
                $errors[$pointer] = 'must be 8 characters a-z and 0-9, or be left out for a new block.';
            } elseif (isset($ids[$id])) {
                $errors[$pointer] = new Message('"{id}" is used by another block of this content.', ['id' => $id]);
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
     * Claims the ids of orphaned blocks and of the blocks inside them.
     * False, with an error, if one is used already.
     *
     * @param array<mixed>          $blocks
     * @param array<string, string|Message> $errors
     * @param array<string, true>   $ids
     */
    private function claimAll(array $blocks, string $pointer, array &$errors, array &$ids): bool
    {
        $found = [];
        self::collect($blocks, $found);

        foreach (array_keys($found) as $id) {
            if (isset($ids[$id])) {
                $errors[$pointer] = new Message('"{id}" is used by another block of this content.', ['id' => $id]);

                return false;
            }
        }

        foreach (array_keys($found) as $id) {
            $ids[$id] = true;
        }

        return true;
    }

    /**
     * The stored blocks by id, at any depth, the view of a plugin too.
     *
     * @param array<mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function index(array $content): array
    {
        $found = [];
        self::collect([$content], $found, true);

        return $found;
    }

    /**
     * @param array<mixed>        $blocks
     * @param array<string, mixed> $found
     */
    private static function collect(array $blocks, array &$found, bool $root = false): void
    {
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }

            if (!$root && \is_string($block['id'] ?? null)) {
                $found[$block['id']] = $block;
            }

            if (\is_array($block['plugin'] ?? null)) {
                self::collect([$block['plugin']], $found);
            }

            foreach (\is_array($block['areas'] ?? null) ? $block['areas'] : [] as $list) {
                if (\is_array($list)) {
                    self::collect($list, $found);
                }
            }

            $root = false;
        }
    }

    /**
     * Whether the value is the one stored under this name.
     *
     * @param array<string, mixed> $stored
     */
    private static function unchanged(array $stored, string $name, mixed $value): bool
    {
        return \array_key_exists($name, $stored) && self::same($stored[$name], $value);
    }

    /**
     * Equal as JSON, whatever the order of the keys of objects.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        return self::sorted($a) === self::sorted($b);
    }

    private static function sorted(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
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
