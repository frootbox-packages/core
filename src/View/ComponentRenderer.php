<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\RichText\Html;
use Psr\Container\ContainerInterface;
use Twig\Environment as Twig;

/**
 * Renders placed blocks and layouts with their areas.
 *
 * Unknown blocks, e.g. from a theme that is no longer active, are left
 * out. In development a comment marks the place.
 */
final readonly class ComponentRenderer
{
    public const int MAX_DEPTH = 20;

    /**
     * @param ContainerInterface $container Twig and the components are loaded on first use, so Twig can be created without them
     */
    public function __construct(
        private FieldTypes $types,
        private ContainerInterface $container,
        private Environment $environment,
    ) {}

    /**
     * @param Instance|array<mixed> $block
     * @param AssetList|null        $assets collects the styles and scripts of everything rendered
     */
    public function block(Instance|array $block, ?AssetList $assets = null): string
    {
        return $this->render(ComponentKind::Block, $block instanceof Instance ? $block : Instance::fromArray($block), 0, $assets);
    }

    /**
     * @param Instance|array<mixed> $layout
     * @param AssetList|null        $assets collects the styles and scripts of everything rendered
     */
    public function layout(Instance|array $layout, ?AssetList $assets = null): string
    {
        return $this->render(ComponentKind::Layout, $layout instanceof Instance ? $layout : Instance::fromArray($layout, ComponentKind::Layout), 0, $assets);
    }

    /**
     * The blocks of an area of the block or layout being rendered.
     *
     * @internal Called by area() in templates
     */
    public function area(Frame $frame, string $name): string
    {
        if (!isset($frame->component->areas[$name])) {
            throw new ViewException(\sprintf(
                '%s has no area "%s". Areas: %s.',
                $frame->component->name,
                $name,
                [] === $frame->component->areas ? 'none' : implode(', ', array_keys($frame->component->areas)),
            ));
        }

        $html = '';

        foreach ($frame->instance->areas[$name] ?? [] as $block) {
            $html .= $this->render(ComponentKind::Block, $block, $frame->depth + 1, $frame->assets);
        }

        return $html;
    }

    private function render(ComponentKind $kind, Instance $instance, int $depth, ?AssetList $assets): string
    {
        $component = $this->service(Components::class)->get($kind, $instance->name);

        if (null === $component || $depth > self::MAX_DEPTH) {
            return $this->environment->isDevelopment()
                ? '<!-- ' . Html::escape(\sprintf(null === $component ? 'Frootbox: unknown %s %s' : 'Frootbox: %s %s is nested too deeply', $kind->value, $instance->name)) . ' -->'
                : '';
        }

        $assets?->addComponent($component);

        return $this->service(Twig::class)->render($component->variant($instance->variant)->template, [
            'id' => $instance->id,
            'options' => $component->variant($instance->variant)->options($instance->options),
            Frame::VARIABLE => new Frame($component, $instance, new Scope($component->fields, $instance->fields, $this->types), $depth, $assets),
        ]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);

        return $service instanceof $class ? $service : throw new ViewException(\sprintf('%s is not available.', $class));
    }
}
