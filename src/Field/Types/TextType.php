<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\RichText\Html;
use Twig\Markup;

/**
 * Plain text without formatting. With "multiline: true" line breaks become <br>.
 */
#[AsFieldType('text', 'Plain text without formatting')]
final readonly class TextType implements FieldType
{
    public function settings(): array
    {
        return [
            'multiline' => ['type' => 'boolean'],
            'maxLength' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000],
        ];
    }

    public function valueSchema(Field $field): array
    {
        $multiline = true === $field->setting('multiline');
        $schema = ['type' => 'string', 'maxLength' => $field->intSetting('maxLength', $multiline ? 5000 : 500)];

        return $multiline ? $schema : [...$schema, 'pattern' => '^[^\\r\\n]*$'];
    }

    public function validate(mixed $value, Field $field): array
    {
        return [];
    }

    public function output(mixed $value, Field $field): Markup|string
    {
        if (!\is_string($value)) {
            return '';
        }

        return true === $field->setting('multiline') ? new Markup(nl2br(Html::escape($value), false), 'UTF-8') : $value;
    }
}
