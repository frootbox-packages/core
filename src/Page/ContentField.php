<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\EntryKey;
use Frootbox\Core\Field\Field;

/**
 * One field in the content of a page: of the layout or of a block found
 * by its id, at a path such as "title" or "slides/0/title" (decision
 * 0122), an entry also by its id: "slides/k3f9x2ab/title" (decision
 * 0344). Reads and replaces its value without touching anything else.
 */
final readonly class ContentField
{
    /**
     * @param string           $root "fields" for the layout, "areas" for a block
     * @param list<string|int> $path The way from there to the value
     * @param list<string|int> $keys The way from the fields of the layout or block
     */
    private function __construct(
        public Field $field,
        private string $root,
        private array $path,
        private array $keys,
    ) {}

    /**
     * Finds the field, or returns why not, by input key.
     *
     * @param array<string, mixed> $content
     *
     * @return self|array<string, string>
     */
    public static function find(Components $components, string $layout, array $content, ?string $block, string $path): self|array
    {
        if (null === $block) {
            $component = $components->get(ComponentKind::Layout, $layout);
            $where = [];
        } else {
            $where = ContentBlocks::locate($content, $block);

            if (null === $where) {
                return ['/block' => 'There is no block with this id in the content.'];
            }

            $found = ContentBlocks::at($content, $where);
            $name = \is_array($found) ? $found['block'] ?? null : null;
            $component = \is_string($name) ? $components->get(ComponentKind::Block, $name) : null;
        }

        if (null === $component) {
            return [null === $block ? '/id' : '/block' => 'Its layout or block is not installed.'];
        }

        $segments = explode('/', $path);
        $fields = $component->fields;
        $keys = [];
        $values = ContentBlocks::at($content, [...$where, 'fields']);

        while (true) {
            $name = array_shift($segments);
            $field = $fields[$name] ?? null;

            if (null === $field) {
                return ['/path' => \sprintf('There is no field "%s".', $name)];
            }

            $keys[] = $name;

            if ([] === $segments) {
                $way = [...$where, 'fields', ...$keys];

                return new self($field, (string) array_shift($way), $way, $keys);
            }

            $segment = array_shift($segments);

            if ([] === $field->fields || 1 !== preg_match('/^' . EntryKey::SEGMENT . '$/D', $segment) || [] === $segments) {
                return ['/path' => \sprintf('"%s" is no list with entries to change; a path looks like "slides/0/title" or "slides/k3f9x2ab/title".', $name)];
            }

            // By position or by id (decision 0344).
            $entries = \is_array($values) ? $values[$name] ?? null : null;
            $index = EntryKey::position($entries, $segment);

            if (null === $index || !\is_array($entries) || !\is_array($entries[$index])) {
                return ['/path' => \sprintf('The list "%s" has no entry %s.', $name, $segment)];
            }

            $keys[] = $index;
            $values = $entries[$index];
            $fields = $field->fields;
        }
    }

    /**
     * The path with positions instead of ids, e.g. "slides/0/title", to
     * find the same field in another language whose entries got other
     * ids.
     */
    public function positions(): string
    {
        return implode('/', $this->keys);
    }

    /**
     * @param array<string, mixed> $content
     */
    public function value(array $content): mixed
    {
        return ContentBlocks::at($content, [$this->root, ...$this->path]);
    }

    /**
     * The content with the new value; null removes it.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function with(array $content, mixed $value): array
    {
        $content[$this->root] = self::put(\is_array($content[$this->root] ?? null) ? $content[$this->root] : [], $this->path, $value);

        return $content;
    }

    /**
     * Whether two values are the same as JSON, whatever the order of keys
     * in objects.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::sorted($a) === self::sorted($b);
    }

    /**
     * @param array<mixed>     $data
     * @param list<string|int> $keys
     *
     * @return array<mixed>
     */
    private static function put(array $data, array $keys, mixed $value): array
    {
        $key = array_shift($keys);

        if (null === $key) {
            return $data;
        }

        if ([] === $keys) {
            if (null === $value) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }

            return $data;
        }

        $data[$key] = self::put(\is_array($data[$key] ?? null) ? $data[$key] : [], $keys, $value);

        return $data;
    }

    private static function sorted(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
