<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\Core\Theme\Icons;
use LogicException;
use Psr\Container\ContainerInterface;
use Twig\Markup;

/**
 * A symbol of the theme by its name, e.g. "truck" (decision 0179). The
 * theme brings the symbols as SVG files in icons/.
 *
 * {{ field('icon') }} gives the SVG inline, hidden from screen readers.
 * A name the theme no longer has gives nothing.
 */
#[AsFieldType('icon', 'A symbol of the theme, e.g. a truck for delivery')]
final readonly class IconType implements FieldType
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
        return ['type' => 'string', 'maxLength' => 64, 'pattern' => Icons::PATTERN];
    }

    public function validate(mixed $value, Field $field): array
    {
        return \is_string($value) && !$this->icons()->has($value)
            ? ['' => \sprintf('names no symbol of the theme. Known are: %s.', implode(', ', $this->icons()->names()))]
            : [];
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
