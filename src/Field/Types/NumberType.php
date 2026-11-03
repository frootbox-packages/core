<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\ObjectOnly;
use Frootbox\Core\View\Delivery;
use LogicException;
use NumberFormatter;
use Psr\Container\ContainerInterface;

/**
 * A number at an object, e.g. the altitude of a farm (decision 0250):
 *
 *     altitude: { type: number, unit: m, decimals: 0, min: 0 }
 *
 * object_field() gives it formatted in the language of the content with
 * its unit, e.g. "1.650 m"; object_value() the number.
 */
#[AsFieldType('number', 'A number at an object, with a unit')]
final readonly class NumberType implements ObjectOnly
{
    /**
     * @param ContainerInterface $container The language is read on output
     */
    public function __construct(private ContainerInterface $container) {}

    public function settings(): array
    {
        return [
            'unit' => ['type' => 'string', 'maxLength' => 20],
            'decimals' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6],
            'min' => ['type' => 'number'],
            'max' => ['type' => 'number'],
        ];
    }

    public function valueSchema(Field $field): array
    {
        $min = $field->setting('min');
        $max = $field->setting('max');

        return [
            'type' => 0 === self::decimals($field) ? 'integer' : 'number',
            ...(\is_int($min) || \is_float($min) ? ['minimum' => $min] : []),
            ...(\is_int($max) || \is_float($max) ? ['maximum' => $max] : []),
        ];
    }

    public function validate(mixed $value, Field $field): array
    {
        $decimals = self::decimals($field);

        if (\is_float($value) && abs(round($value, $decimals) - $value) > 1e-9) {
            return ['' => \sprintf('may have at most %d decimal places.', $decimals)];
        }

        return [];
    }

    public function output(mixed $value, Field $field): string
    {
        if (!\is_int($value) && !\is_float($value)) {
            return '';
        }

        $language = $this->container->get(Delivery::class);

        if (!$language instanceof Delivery) {
            throw new LogicException('The delivery is not available.');
        }

        return self::format($value, $field, $language->contentLanguage()->code);
    }

    /**
     * The number in a language with its unit, e.g. "1.650 m".
     */
    public static function format(int|float $value, Field $field, string $language): string
    {
        $decimals = self::decimals($field);
        $number = new NumberFormatter($language, NumberFormatter::DECIMAL);
        $number->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $number->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $formatted = $number->format($value);
        $formatted = false === $formatted ? number_format($value, $decimals) : $formatted;
        $unit = $field->setting('unit');

        // A protected space keeps the unit with the number.
        return \is_string($unit) && '' !== trim($unit) ? $formatted . "\u{00A0}" . trim($unit) : $formatted;
    }

    private static function decimals(Field $field): int
    {
        return max(0, min(6, $field->intSetting('decimals', 0) ?? 0));
    }
}
