<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Field;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;

#[AsFieldType('acme/shop/price')]
final readonly class PriceType implements FieldType
{
    public function settings(): array
    {
        return [];
    }

    public function valueSchema(Field $field): array
    {
        return ['type' => 'number'];
    }

    public function validate(mixed $value, Field $field): array
    {
        return [];
    }

    public function output(mixed $value, Field $field): string
    {
        return \is_int($value) ? number_format($value / 100, 2) : '';
    }
}
