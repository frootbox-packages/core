<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\ReferencesMedia;
use Frootbox\Core\Theme\Icons;
use LogicException;
use Psr\Container\ContainerInterface;
use Twig\Markup;

/**
 * A symbol by its name (decisions 0179, 0182): one of the theme like
 * "truck", one of an extension like "acme/fontawesome/solid/truck" or
 * an SVG graphic of the media library like "media:01J…".
 *
 * {{ field('icon') }} gives the SVG inline, hidden from screen readers.
 * A symbol that is gone gives nothing.
 */
#[AsFieldType('icon', 'A symbol, e.g. a truck for delivery')]
final readonly class IconType implements ReferencesMedia
{
    /**
     * @param ContainerInterface $container The symbols are loaded on first use
     */
    public function __construct(private ContainerInterface $container) {}

    public function settings(): array
    {
        return [];
    }

    public function valueSchema(Field $field): array
    {
        return ['type' => 'string', 'maxLength' => 200, 'pattern' => Icons::PATTERN];
    }

    public function validate(mixed $value, Field $field): array
    {
        // A graphic deleted later must not keep the page from being saved.
        return \is_string($value) && null === Icons::mediumId($value) && !$this->icons()->has($value)
            ? ['' => 'names no symbol that is installed. icon.list tells the available ones.']
            : [];
    }

    public function media(mixed $value, Field $field): array
    {
        $id = \is_string($value) ? Icons::mediumId($value) : null;

        return null === $id ? [] : [$id];
    }

    public function output(mixed $value, Field $field): Markup|string
    {
        $svg = \is_string($value) ? $this->icons()->svg($value) : null;

        return null === $svg ? '' : new Markup($svg, 'UTF-8');
    }

    private function icons(): Icons
    {
        $icons = $this->container->get(Icons::class);

        return $icons instanceof Icons ? $icons : throw new LogicException('The symbols are not available.');
    }
}
