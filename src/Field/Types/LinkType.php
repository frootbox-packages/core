<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\RichText\Attribute;
use Frootbox\RichText\Html;
use Twig\Markup;

/**
 * A link with URL, text and whether it opens in a new window:
 * {"url": "/contact", "text": "Write to us", "newWindow": false}.
 *
 * {{ field('button') }} gives the <a> element. For own markup use
 * value('button').url and value('button').text.
 */
#[AsFieldType('link', 'A link with URL and text')]
final readonly class LinkType implements FieldType
{
    public function settings(): array
    {
        return [];
    }

    public function valueSchema(Field $field): array
    {
        return [
            'type' => 'object',
            'required' => ['url'],
            'additionalProperties' => false,
            'properties' => [
                'url' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'text' => ['type' => 'string', 'maxLength' => 200],
                'newWindow' => ['type' => 'boolean'],
            ],
        ];
    }

    public function validate(mixed $value, Field $field): array
    {
        [, $error] = Attribute::url(required: true)->clean(\is_array($value) ? $value['url'] ?? null : null);

        return null === $error ? [] : ['/url' => $error];
    }

    public function output(mixed $value, Field $field): Markup|string
    {
        if (!\is_array($value) || [] !== $this->validate($value, $field)) {
            return '';
        }

        $url = \is_string($value['url']) ? trim($value['url']) : '';
        $text = \is_string($value['text'] ?? null) && '' !== $value['text'] ? $value['text'] : $url;
        $newWindow = true === ($value['newWindow'] ?? false);

        return new Markup(Html::element('a', Html::escape($text), [
            'href' => $url,
            'target' => $newWindow ? '_blank' : null,
            'rel' => $newWindow ? 'noopener noreferrer' : null,
        ]), 'UTF-8');
    }
}
