<?php

/*
 * SPDX-License-Identifier: MIT
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
     * @param int|null   $min    Entries a list needs, $max it allows (decision 0145)
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public string $description,
        public bool $optional,
        public array $fields,
        public string $placeholder = '',
        public ?int $min = null,
        public ?int $max = null,
    ) {}

    public static function of(Field $field): self
    {
        return new self(
            $field->name,
            $field->type,
            $field->label,
            $field->description,
            $field->optional,
            self::all($field->fields),
            $field->placeholder,
            [] === $field->fields ? null : $field->intSetting('min'),
            [] === $field->fields ? null : $field->intSetting('max'),
        );
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
