<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Component\ContentValidator;
use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\HasSubfields;
use Frootbox\Core\Field\Normalizes;
use Frootbox\Core\Field\ReferencesMedia;
use Frootbox\Core\Field\Searchable;
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
 *
 * The value is a list of entries, each with the values of its fields and
 * an id the core gives it on saving (decision 0344):
 *
 *     [{"id": "k3f9x2ab", "title": "First slide"}, {"id": "p0q7m1zc", "title": "Second slide"}]
 *
 * In the view field('slides') gives the entries to loop over, each with
 * field(), value() and heading() of its own; visitors get only entries
 * with a value:
 *
 *     {% for slide in field('slides') %}
 *       <li>{{ slide.heading('title', 1) }}</li>
 *     {% endfor %}
 */
#[AsFieldType('list', 'Repeated entries with their own fields')]
final readonly class ListType implements HasSubfields, Normalizes, ReferencesMedia, Searchable
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
        $schema = ['type' => 'array', 'items' => $this->types->entrySchema($field->fields)];
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

    /**
     * Gives each entry an id, keeps the ones it has, and replaces doubles
     * (decision 0344); lists inside entries too.
     */
    public function normalize(mixed $value, Field $field): mixed
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return $value;
        }

        $seen = [];

        foreach ($value as $index => $entry) {
            // A new entry without values comes as an empty object.
            $entry = $entry instanceof \stdClass ? (array) $entry : $entry;

            if (!\is_array($entry)) {
                continue;
            }

            $id = $entry['id'] ?? null;

            while (!\is_string($id) || 1 !== preg_match('/' . ContentValidator::ID_PATTERN . '/', $id) || isset($seen[$id])) {
                $id = ContentValidator::newId();
            }

            \assert(\is_string($id));
            $seen[$id] = true;
            unset($entry['id']);
            $value[$index] = ['id' => $id, ...$this->types->normalize($field->fields, $entry)];
        }

        return $value;
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

    public function searchText(mixed $value, Field $field): string
    {
        $texts = [];

        foreach (\is_array($value) ? $value : [] as $entry) {
            $texts[] = \is_array($entry) ? $this->types->text($field->fields, $entry) : '';
        }

        return implode("\n", array_filter($texts, static fn(string $text): bool => '' !== $text));
    }
}
