<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\RichText\Attribute;
use Frootbox\RichText\Html;
use Frootbox\RichText\MarkType;
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * The link mark of rich text, with targets as href (decision 0105):
 * {"type": "link", "attrs": {"href": "page:01J…"}} links to the current
 * URL of the page in the visitor's language. A target without URL leaves
 * the text without link.
 */
final readonly class TargetLink implements MarkType
{
    /**
     * @param ContainerInterface $container The URLs are loaded on first output
     */
    public function __construct(private ContainerInterface $container) {}

    public function name(): string
    {
        return 'link';
    }

    public function attributes(): array
    {
        return [
            'href' => Attribute::url(required: true, protocols: [...Attribute::PROTOCOLS, Target::PAGE]),
            'target' => Attribute::choice(['_blank']),
        ];
    }

    public function open(array $attributes): string
    {
        $href = $this->href($attributes);

        if (null === $href) {
            return '';
        }

        $newWindow = '_blank' === ($attributes['target'] ?? null);

        return Html::open('a', [
            'href' => $href,
            'target' => $newWindow ? '_blank' : null,
            'rel' => $newWindow ? 'noopener noreferrer' : null,
        ]);
    }

    public function close(array $attributes): string
    {
        return null === $this->href($attributes) ? '' : Html::close('a');
    }

    /**
     * @param array<string, string|int|null> $attributes
     */
    private function href(array $attributes): ?string
    {
        $href = $attributes['href'] ?? null;

        if (!\is_string($href)) {
            return null;
        }

        $target = Target::tryParse($href);

        if (null === $target) {
            return $href;
        }

        $urls = $this->container->get(Urls::class);

        return $urls instanceof Urls ? $urls->href($target) : throw new LogicException('The URLs are not available.');
    }
}
