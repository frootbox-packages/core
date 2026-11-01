<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Field\Field;

/**
 * A field of a block in block.list, for its settings (decision 0123).
 */
final readonly class FieldSummary
{
    /**
     * @param list<self> $fields Fields of each entry of a list field
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public string $description,
        public bool $optional,
        public array $fields,
        public string $placeholder = '',
    ) {}

    public static function of(Field $field): self
    {
        return new self($field->name, $field->type, $field->label, $field->description, $field->optional, self::all($field->fields), $field->placeholder);
    }

    /**
     * @param array<string, Field> $fields
     *
     * @return list<self>
     */
    public static function all(array $fields): array
    {
        return array_values(array_map(self::of(...), $fields));
    }
}
