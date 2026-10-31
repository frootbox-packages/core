<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use InvalidArgumentException;

/**
 * A placed block or layout with its content:
 *
 *     {"id": "k3x9q2mf", "block": "frootbox/base/Teaser", "variant": "ImageLeft",
 *      "options": {"imageHeight": 550}, "fields": {"title": "Hello"},
 *      "areas": {"main": [ …more blocks… ]}}
 *
 * Layouts use the key "layout" instead of "block". The id identifies a
 * block within its document (decision 0100); layouts have none.
 */
final readonly class Instance
{
    /**
     * @param array<string, mixed>       $options
     * @param array<string, mixed>       $fields
     * @param array<string, list<self>>  $areas
     */
    public function __construct(
        public string $name,
        public ?string $variant = null,
        public array $options = [],
        public array $fields = [],
        public array $areas = [],
        public ?string $id = null,
    ) {}

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException If the structure is wrong
     */
    public static function fromArray(array $data, ComponentKind $kind = ComponentKind::Block): self
    {
        $name = $data[$kind->value] ?? null;
        $variant = $data['variant'] ?? null;
        $id = $data['id'] ?? null;

        if (!\is_string($name)) {
            throw new InvalidArgumentException(\sprintf('A %s needs its name under "%s".', $kind->value, $kind->value));
        }

        if (null !== $variant && !\is_string($variant)) {
            throw new InvalidArgumentException('"variant" must be a name.');
        }

        if (null !== $id && !\is_string($id)) {
            throw new InvalidArgumentException('"id" must be a string.');
        }

        $areas = [];

        foreach (self::map($data['areas'] ?? [], 'areas') as $area => $blocks) {
            if (!\is_array($blocks) || !array_is_list($blocks)) {
                throw new InvalidArgumentException(\sprintf('The area "%s" must be a list of blocks.', $area));
            }

            $areas[$area] = array_map(static fn(mixed $block): self => \is_array($block) ? self::fromArray($block) : throw new InvalidArgumentException(\sprintf('The area "%s" must be a list of blocks.', $area)), $blocks);
        }

        return new self($name, $variant, self::map($data['options'] ?? [], 'options'), self::map($data['fields'] ?? [], 'fields'), $areas, $id);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value, string $key): array
    {
        if (!\is_array($value) || (array_is_list($value) && [] !== $value)) {
            throw new InvalidArgumentException(\sprintf('"%s" must be an object.', $key));
        }

        $map = [];

        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }
}
