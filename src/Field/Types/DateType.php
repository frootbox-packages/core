<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use DateTimeImmutable;
use DateTimeZone;
use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\ObjectOnly;
use Frootbox\Core\View\Delivery;
use Frootbox\RichText\Html;
use IntlDateFormatter;
use LogicException;
use Psr\Container\ContainerInterface;
use Twig\Markup;

/**
 * A day at an object, e.g. when a project was finished (decision 0250),
 * stored as "2026-05-01". object_field() gives a <time> element with the
 * long date in the language of the content, object_value() the text.
 */
#[AsFieldType('date', 'A day at an object, e.g. "2026-05-01"')]
final readonly class DateType implements ObjectOnly
{
    public const string PATTERN = '^[0-9]{4}-[0-9]{2}-[0-9]{2}$';

    /**
     * @param ContainerInterface $container The language is read on output
     */
    public function __construct(private ContainerInterface $container) {}

    public function settings(): array
    {
        return [];
    }

    public function valueSchema(Field $field): array
    {
        return ['type' => 'string', 'pattern' => self::PATTERN];
    }

    public function validate(mixed $value, Field $field): array
    {
        return null === self::day($value) ? ['' => 'is not a day, e.g. "2026-05-01".'] : [];
    }

    public function output(mixed $value, Field $field): Markup|string
    {
        $day = self::day($value);

        if (null === $day || !\is_string($value)) {
            return '';
        }

        $delivery = $this->container->get(Delivery::class);

        if (!$delivery instanceof Delivery) {
            throw new LogicException('The delivery is not available.');
        }

        return new Markup(Html::element('time', Html::escape(self::format($day, $delivery->contentLanguage()->code)), ['datetime' => $value]), 'UTF-8');
    }

    /**
     * The long date in a language, e.g. "1. Mai 2026".
     */
    public static function format(DateTimeImmutable $day, string $language): string
    {
        $formatted = new IntlDateFormatter($language, IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC')->format($day);

        return \is_string($formatted) ? $formatted : $day->format('Y-m-d');
    }

    public static function day(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/' . self::PATTERN . '/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year) ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
    }
}
