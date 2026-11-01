<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\HasSubfields;
use Frootbox\Core\Field\ReferencesMedia;
use Frootbox\Core\View\ListValue;
use Frootbox\Core\View\Scope;

/**
 * Repeated entries with fields of their own, e.g. slides or FAQ items:
 *
 *     slides:
 *       type: list
 *       max: 10
 *       start: 3
 *       fields:
 *         title: { type: headline }
 */
#[AsFieldType('list', 'Repeated entries with their own fields')]
final readonly class ListType implements HasSubfields, ReferencesMedia
{
    public function __construct(private FieldTypes $types) {}

    public function settings(): array
    {
        return [
            'min' => ['type' => 'integer', 'minimum' => 0],
            'max' => ['type' => 'integer', 'minimum' => 1],
            // Entries a new block begins with (decision 0142), else min
            'start' => ['type' => 'integer', 'minimum' => 0],
        ];
    }

    public function valueSchema(Field $field): array
    {
        $schema = ['type' => 'array', 'items' => $this->types->schemaFor($field->fields)];
        $min = $field->intSetting('min');
        $max = $field->intSetting('max');

        return [
            ...$schema,
            ...(null === $min ? [] : ['minItems' => $min]),
            ...(null === $max ? [] : ['maxItems' => $max]),
        ];
    }

    public function validate(mixed $value, Field $field): array
    {
        $errors = [];

        foreach (\is_array($value) ? array_values($value) : [] as $index => $entry) {
            foreach (\is_array($entry) ? $this->types->validate($field->fields, $entry) : [] as $pointer => $message) {
                $errors['/' . $index . $pointer] = $message;
            }
        }

        return $errors;
    }

    public function media(mixed $value, Field $field): array
    {
        $ids = [];

        foreach (\is_array($value) ? $value : [] as $entry) {
            array_push($ids, ...(\is_array($entry) ? $this->types->media($field->fields, $entry) : []));
        }

        return $ids;
    }

    public function output(mixed $value, Field $field): ListValue
    {
        $entries = [];

        foreach (\is_array($value) ? array_values($value) : [] as $entry) {
            $entries[] = new Scope($field->fields, \is_array($entry) ? $entry : [], $this->types);
        }

        return new ListValue($entries);
    }
}
