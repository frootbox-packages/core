<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;

/**
 * The fields of a block, a layout or one list entry with their values,
 * as seen by field() and value() in a template.
 */
final readonly class Scope
{
    /**
     * @param array<string, Field> $fields
     * @param array<mixed>         $values
     */
    public function __construct(
        private array $fields,
        private array $values,
        private FieldTypes $types,
    ) {}

    /**
     * The field ready for output, e.g. {{ slide.field('title') }}.
     */
    public function field(string $name): mixed
    {
        $field = $this->definition($name);

        return $this->types->get($field->type)->output($this->values[$name] ?? null, $field);
    }

    /**
     * The stored value, e.g. for attributes and conditions.
     */
    public function value(string $name): mixed
    {
        $this->definition($name);

        return $this->values[$name] ?? null;
    }

    private function definition(string $name): Field
    {
        return $this->fields[$name] ?? throw new ViewException(\sprintf(
            'There is no field "%s". Fields: %s.',
            $name,
            [] === $this->fields ? 'none' : implode(', ', array_keys($this->fields)),
        ));
    }
}
