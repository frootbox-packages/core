<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\Searchable;
use Frootbox\RichText\Html;
use Twig\Markup;

/**
 * Plain text without formatting. With "multiline: true" line breaks become
 * <br> alone: an editable field shows a line break next to it as a second
 * one and reads it back doubled.
 */
#[AsFieldType('text', 'Plain text without formatting')]
final readonly class TextType implements Searchable
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

        if (true !== $field->setting('multiline')) {
            return $value;
        }

        return new Markup(str_replace(["\r\n", "\r", "\n"], '<br>', Html::escape($value)), 'UTF-8');
    }

    public function searchText(mixed $value, Field $field): string
    {
        return \is_string($value) ? $value : '';
    }
}
