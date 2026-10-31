<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Functions for block and layout templates:
 *
 *     {{ field('title') }}           the field, ready for output
 *     {{ value('button').url }}      the stored value
 *     {{ area('columns') }}          the blocks placed in an area
 */
final class ViewExtension extends AbstractExtension
{
    public function __construct(private readonly ComponentRenderer $renderer) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('field', fn(array $context, string $name): mixed => self::frame($context, 'field')->scope->field($name), ['needs_context' => true]),
            new TwigFunction('value', fn(array $context, string $name): mixed => self::frame($context, 'value')->scope->value($name), ['needs_context' => true]),
            new TwigFunction('area', fn(array $context, string $name): Markup => new Markup($this->renderer->area(self::frame($context, 'area'), $name), 'UTF-8'), ['needs_context' => true]),
        ];
    }

    /**
     * @param array<mixed> $context
     */
    private static function frame(array $context, string $function): Frame
    {
        $frame = $context[Frame::VARIABLE] ?? null;

        return $frame instanceof Frame ? $frame : throw new RuntimeError(\sprintf('%s() works only in templates of blocks and layouts.', $function));
    }
}
