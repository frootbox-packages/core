<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\ObjectOnly;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\View\Delivery;
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * One of several options at an object, e.g. the variety of a coffee
 * (decision 0250). The value is the key, the text comes from the options,
 * one for all languages or one per language:
 *
 *     variety: { type: choice, options: { arabica: Arabica, robusta: { de: Robusta, en: Robusta } } }
 *
 * object_field() gives the text in the language of the content or its
 * fallback, object_value() the key.
 */
#[AsFieldType('choice', 'One of several options at an object')]
final readonly class ChoiceType implements ObjectOnly
{
    public const string KEY = '^[a-z0-9][a-z0-9-]*$';

    /**
     * @param ContainerInterface $container The language is read on output
     */
    public function __construct(private ContainerInterface $container) {}

    public function settings(): array
    {
        return [
            'options' => [
                'type' => 'object',
                'minProperties' => 1,
                'propertyNames' => ['pattern' => self::KEY],
                'additionalProperties' => [
                    'oneOf' => [
                        ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                        ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => ['type' => 'string', 'maxLength' => 200]],
                    ],
                ],
            ],
        ];
    }

    public function valueSchema(Field $field): array
    {
        $keys = array_keys(self::options($field));

        return ['type' => 'string', ...([] === $keys ? [] : ['enum' => $keys])];
    }

    public function validate(mixed $value, Field $field): array
    {
        return \is_string($value) && \array_key_exists($value, self::options($field)) ? [] : ['' => 'is not one of the options.'];
    }

    public function output(mixed $value, Field $field): string
    {
        if (!\is_string($value) || !\array_key_exists($value, self::options($field))) {
            return '';
        }

        $delivery = $this->container->get(Delivery::class);
        $languages = $this->container->get(Languages::class);

        if (!$delivery instanceof Delivery || !$languages instanceof Languages) {
            throw new LogicException('The delivery is not available.');
        }

        return self::label($field, $value, $languages->contentLanguages($delivery->contentLanguage()->code));
    }

    /**
     * The text of an option in the first of the languages that has one,
     * else in any.
     *
     * @param list<string> $languages
     */
    public static function label(Field $field, string $key, array $languages): string
    {
        $label = self::options($field)[$key] ?? null;

        if (\is_string($label)) {
            return $label;
        }

        if (!\is_array($label)) {
            return $key;
        }

        foreach ($languages as $language) {
            if (\is_string($label[$language] ?? null) && '' !== $label[$language]) {
                return $label[$language];
            }
        }

        foreach ($label as $text) {
            if (\is_string($text) && '' !== $text) {
                return $text;
            }
        }

        return $key;
    }

    /**
     * @return array<string, mixed> By key
     */
    public static function options(Field $field): array
    {
        $options = $field->setting('options');
        $result = [];

        foreach (\is_array($options) ? $options : [] as $key => $label) {
            $result[(string) $key] = $label;
        }

        return $result;
    }
}
