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
use Frootbox\Core\Heading\Headings;
use Frootbox\Core\Heading\Outline;
use Frootbox\Core\Page\EditMode;
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

    /** The class of the h1 a page without one gets (decision 0129). */
    public const string FALLBACK = 'fb-visually-hidden';

    /** Hidden from the eyes, read by screen readers. */
    private const string HIDDEN = '.fb-visually-hidden{position:absolute!important;inline-size:1px!important;block-size:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important}';

    /**
     * @param ContainerInterface $container Twig and the components are loaded on first use, so Twig can be created without them
     */
    public function __construct(
        private FieldTypes $types,
        private ContainerInterface $container,
        private Environment $environment,
        private EditMode $editMode,
        private EditMarkup $markup,
    ) {}

    /**
     * @param Instance|array<mixed> $block
     * @param AssetList|null        $assets   collects the styles and scripts of everything rendered
     * @param Headings|null         $headings where it stands, by default on top of a page
     */
    public function block(Instance|array $block, ?AssetList $assets = null, ?Headings $headings = null): string
    {
        return $this->render(ComponentKind::Block, $block instanceof Instance ? $block : Instance::fromArray($block), 0, $assets, $headings ?? new Headings());
    }

    /**
     * A layout with its blocks. Their headings get their levels from where
     * they stand (decision 0129). A page without any h1 gets its title as
     * one for screen readers, first in <main>.
     *
     * @param Instance|array<mixed> $layout
     * @param AssetList|null        $assets  collects the styles and scripts of everything rendered
     * @param string|null           $title   the title of the page, for a page without h1
     * @param Outline|null          $outline collects the headings, e.g. for page.outline
     */
    public function layout(Instance|array $layout, ?AssetList $assets = null, ?string $title = null, ?Outline $outline = null): string
    {
        $html = $this->render(ComponentKind::Layout, $layout instanceof Instance ? $layout : Instance::fromArray($layout, ComponentKind::Layout), 0, $assets, new Headings($outline ?? new Outline()));

        if (null === $title || '' === trim($title) || 1 === preg_match('/<h1[\s>]/i', $html)) {
            return $html;
        }

        $assets?->addStyle(self::HIDDEN);
        $delivery = $this->service(Delivery::class);
        $heading = Html::element('h1', Html::escape($title), ['class' => self::FALLBACK, 'lang' => $delivery->isFallback() ? $delivery->contentLanguage()->code : null]);
        $main = preg_replace_callback('/<main(?:\s[^>]*)?>/i', static fn(array $match): string => $match[0] . $heading, $html, 1, $count);

        return 1 === $count && \is_string($main) ? $main : $heading . $html;
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

        // Content from a fallback language gets its language marked for screen
        // readers (decision 0105), and its direction where it differs (0133).
        $delivery = 0 === $frame->depth ? $this->service(Delivery::class) : null;
        [$language, $direction] = [null, null];

        if (null !== $delivery && $delivery->isFallback()) {
            $language = $delivery->contentLanguage();
            $direction = $language->direction === $delivery->language()->direction ? null : $language->direction->value;
        }
        $html = '';
        $marking = $this->editMode->marking();
        $marked = $marking || $this->editMode->anchors();

        $level = $frame->component->areas[$name]->level;

        foreach ($frame->instance->areas[$name] ?? [] as $block) {
            $rendered = $this->render(ComponentKind::Block, $block, $frame->depth + 1, $frame->assets, $frame->headings->inArea($level, $block->id));
            $rendered = null === $language ? $rendered : self::withLanguage($rendered, $language->code, $direction);
            $component = $marked ? $this->service(Components::class)->get(ComponentKind::Block, $block->name) : null;
            $html .= null === $component ? $rendered : $this->markup->block($rendered, $block, $component, $name);
        }

        return $marking && '' === $html ? $this->markup->emptyArea($name) : $html;
    }

    /**
     * Adds lang="…" and dir="…" to the outer element of a rendered block,
     * unless it has them.
     */
    private static function withLanguage(string $html, string $language, ?string $direction): string
    {
        $tag = '/^(\s*(?:<!--.*?-->\s*)*<[a-zA-Z][a-zA-Z0-9-]*)(?=[\s>\/])';
        // dir first, so lang comes before it.
        $html = null === $direction ? $html : (string) preg_replace($tag . '(?![^>]*\sdir=)/s', '$1 dir="' . Html::escape($direction) . '"', $html, 1);

        return (string) preg_replace($tag . '(?![^>]*\slang=)/s', '$1 lang="' . Html::escape($language) . '"', $html, 1);
    }

    private function render(ComponentKind $kind, Instance $instance, int $depth, ?AssetList $assets, Headings $headings): string
    {
        $component = $this->service(Components::class)->get($kind, $instance->name);

        if (null === $component || $depth > self::MAX_DEPTH) {
            return $this->environment->isDevelopment()
                ? '<!-- ' . Html::escape(\sprintf(null === $component ? 'Frootbox: unknown %s %s' : 'Frootbox: %s %s is nested too deeply', $kind->value, $instance->name)) . ' -->'
                : '';
        }

        $assets?->addComponent($component);

        $markup = match (true) {
            $this->editMode->marking() => $this->markup,
            $this->editMode->anchors() => $this->markup->forPreview(),
            default => null,
        };
        $html = $this->service(Twig::class)->render($component->variant($instance->variant)->template, [
            'id' => $instance->id,
            'options' => $component->variant($instance->variant)->options($instance->options),
            Frame::VARIABLE => new Frame($component, $instance, new Scope($component->fields, $instance->fields, $this->types, $markup, headings: $headings), $depth, $assets, $headings),
        ]);

        return match (true) {
            null === $markup => EmptyElements::remove($html),
            // A preview: the page as visitors get it, with light marks (decision 0156).
            $markup->anchors => $markup->strip($markup->unwrap(EmptyElements::remove($html), keepRoot: ComponentKind::Block === $kind)),
            default => $markup->unwrap($html, keepRoot: ComponentKind::Block === $kind),
        };
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
