<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\ObjectOnly;

/**
 * Yes or no at an object, e.g. "noContactDetails" (decisions 0234,
 * 0240). Templates ask with object_value(); left out is no.
 */
#[AsFieldType('boolean', 'Yes or no at an object, e.g. a switch for the view')]
final readonly class BooleanType implements ObjectOnly
{
    public function settings(): array
    {
        return [];
    }

    public function valueSchema(Field $field): array
    {
        return ['type' => 'boolean'];
    }

    public function validate(mixed $value, Field $field): array
    {
        return [];
    }

    public function output(mixed $value, Field $field): bool
    {
        return true === $value;
    }
}
