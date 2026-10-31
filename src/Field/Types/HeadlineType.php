<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;

/**
 * A single line of text for a heading. The template sets the tag:
 * <h2>{{ field('title') }}</h2>.
 */
#[AsFieldType('headline', 'A single line for a heading')]
final readonly class HeadlineType implements FieldType
{
    public const int MAX_LENGTH = 200;

    public function settings(): array
    {
        return ['maxLength' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000]];
    }

    public function valueSchema(Field $field): array
    {
        return ['type' => 'string', 'maxLength' => $field->intSetting('maxLength', self::MAX_LENGTH), 'pattern' => '^[^\\r\\n]*$'];
    }

    public function validate(mixed $value, Field $field): array
    {
        return [];
    }

    public function output(mixed $value, Field $field): string
    {
        return \is_string($value) ? $value : '';
    }
}
