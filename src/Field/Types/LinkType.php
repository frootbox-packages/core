<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Frootbox\RichText\Attribute;
use Frootbox\RichText\Html;
use LogicException;
use Psr\Container\ContainerInterface;
use Twig\Markup;

/**
 * A link to a target or a URL, with text and whether it opens in a new
 * window (decision 0105):
 *
 *     {"target": "page:01J…", "text": "About us"}
 *     {"url": "https://example.com", "text": "Elsewhere", "newWindow": true}
 *
 * A target always gives its current URL in the visitor's language. A
 * target without URL, e.g. a deleted page, gives only the text.
 *
 * {{ field('button') }} gives the <a> element. For own markup use
 * url(value('button')) and value('button').text.
 */
#[AsFieldType('link', 'A link to a page or a URL, with text')]
final readonly class LinkType implements FieldType
{
    /**
     * @param ContainerInterface $container The URLs are loaded on first output
     */
    public function __construct(private ContainerInterface $container) {}

    public function settings(): array
    {
        return [];
    }

    public function valueSchema(Field $field): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'url' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'target' => ['type' => 'string', 'pattern' => Target::PATTERN],
                'text' => ['type' => 'string', 'maxLength' => 200],
                'newWindow' => ['type' => 'boolean'],
            ],
        ];
    }

    public function validate(mixed $value, Field $field): array
    {
        $value = \is_array($value) ? $value : [];

        if (isset($value['url']) === isset($value['target'])) {
            return ['' => 'needs either "target" (e.g. "page:01J…") or "url".'];
        }

        if (isset($value['target'])) {
            return [];
        }

        [, $error] = Attribute::url(required: true)->clean($value['url'] ?? null);

        return null === $error ? [] : ['/url' => $error];
    }

    public function output(mixed $value, Field $field): Markup|string
    {
        if (!\is_array($value) || [] !== $this->validate($value, $field)) {
            return '';
        }

        $href = \is_string($value['target'] ?? null) ? $this->urls()->href($value['target']) : (\is_string($value['url'] ?? null) ? trim($value['url']) : null);
        $text = \is_string($value['text'] ?? null) && '' !== $value['text'] ? $value['text'] : ($href ?? '');

        if (null === $href) {
            return $text;
        }

        $newWindow = true === ($value['newWindow'] ?? false);

        return new Markup(Html::element('a', Html::escape($text), [
            'href' => $href,
            'target' => $newWindow ? '_blank' : null,
            'rel' => $newWindow ? 'noopener noreferrer' : null,
        ]), 'UTF-8');
    }

    private function urls(): Urls
    {
        $urls = $this->container->get(Urls::class);

        return $urls instanceof Urls ? $urls : throw new LogicException('The URLs are not available.');
    }
}
