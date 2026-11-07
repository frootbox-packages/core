<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

use Frootbox\Core\Support\Message;

/**
 * A kind of content field, e.g. a headline or a list. Register it with
 * $registry->fieldType() and name it with #[AsFieldType].
 */
interface FieldType
{
    /**
     * JSON schemas of the settings this type accepts in YAML, besides type,
     * label, description and optional. E.g. ['maxLength' => ['type' => 'integer', 'minimum' => 1]].
     *
     * @return array<string, array<string, mixed>>
     */
    public function settings(): array;

    /**
     * JSON schema of a stored value.
     *
     * @return array<string, mixed>
     */
    public function valueSchema(Field $field): array;

    /**
     * Checks the JSON schema cannot express. The value already fits the schema.
     *
     * @return array<string, string|Message> Message by JSON pointer below the value, e.g. "/url", a Message to translate one with values
     */
    public function validate(mixed $value, Field $field): array;

    /**
     * What {{ field('name') }} gives the template: text (escaped by Twig),
     * Twig\Markup for HTML, or something to loop over.
     */
    public function output(mixed $value, Field $field): mixed;
}
