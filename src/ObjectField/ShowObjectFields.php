<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;

/**
 * The further fields of a type of objects (decisions 0234, 0240), for
 * the API, the AI, the app and the form of the module. They come from
 * the views of the active theme, so they change with it.
 *
 * @phpstan-type Described array{name: string, type: string, label: string, description: string, translatable: bool, settings: array<string, mixed>, value: array<string, mixed>, fields: list<array<string, mixed>>, sources: list<string>, labels: array<string, string>}
 *
 * @implements Query<list<Described>>
 */
#[AsQuery('object.fields', 'The further fields of a type of objects, e.g. "frootbox/jobs/job", with "space" also those of a space, e.g. a collection: name, field type, label (and labels by language), the JSON schema of a value and whether it holds one per language. Actions of the module take them as "fields".', signedIn: true)]
final readonly class ShowObjectFields implements Query
{
    public function __construct(
        #[Description('The type of the objects, e.g. "frootbox/news/article"')]
        public string $type,
        #[Description('With the fields of this space, e.g. those of a collection from the backend')]
        public ?string $space = null,
    ) {}

    /**
     * @return list<Described>
     */
    public function handle(ObjectSchema $schema, FieldTypes $types): array
    {
        $result = [];

        foreach ($schema->fields($this->type, $this->space) as $name => $field) {
            $result[] = [...self::describe($field->field, $types), 'name' => $name, 'translatable' => $field->translatable, 'labels' => $field->labels, 'sources' => $field->sources];
        }

        return $result;
    }

    /**
     * @return Described
     */
    private static function describe(Field $field, FieldTypes $types): array
    {
        $settings = $field->settings;
        unset($settings['translatable'], $settings['labels']);

        return [
            'name' => $field->name,
            'type' => $field->type,
            'label' => $field->label,
            'description' => $field->description,
            'translatable' => false,
            'settings' => $settings,
            'value' => $types->get($field->type)->valueSchema($field),
            'fields' => array_values(array_map(static fn(Field $sub): array => self::describe($sub, $types), $field->fields)),
            'sources' => [],
            'labels' => [],
        ];
    }
}
