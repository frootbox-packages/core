<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Component\BlockContext;
use Frootbox\Core\Component\BlockController;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Heading\Headings;
use Frootbox\Core\Heading\Outline;
use Frootbox\Core\I18n\Labels;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Plugin\Plugins;
use Frootbox\RichText\Html;
use InvalidArgumentException;
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
        $block = $block instanceof Instance ? $block : Instance::fromArray($block);
        $this->collectJumpMarks($block);

        return $this->render(ComponentKind::Block, $block, 0, $assets, $headings ?? new Headings());
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
        $layout = $layout instanceof Instance ? $layout : Instance::fromArray($layout, ComponentKind::Layout);
        $this->collectJumpMarks($layout);
        $html = $this->render(ComponentKind::Layout, $layout, 0, $assets, new Headings($outline ?? new Outline()));

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
     * The view of the plugin whose space the page shows (decision 0232),
     * between the areas of the layout. Empty on a page without one. In
     * the edit mode it is a block that stays at its place.
     *
     * @internal Called by plugin() in templates
     */
    public function plugin(Frame $frame): string
    {
        if (ComponentKind::Layout !== $frame->component->kind || 0 !== $frame->depth) {
            throw new ViewException(\sprintf('plugin() belongs to the view of a layout, not to %s.', $frame->component->name));
        }

        if (!$frame->component->plugin) {
            throw new ViewException(\sprintf('%s calls plugin(), so its head needs "plugin: true".', $frame->component->name));
        }

        $delivery = $this->service(Delivery::class);
        $place = $delivery->plugin();
        $plugin = null === $place ? null : $this->service(Plugins::class)->get($place->type);

        if (null === $plugin) {
            return null !== $place && $this->environment->isDevelopment() ? '<!-- ' . Html::escape(\sprintf('Frootbox: no plugin %s', $place->type)) . ' -->' : '';
        }

        $view = $delivery->pluginView() ?? [];

        try {
            $instance = ($view['block'] ?? null) === $plugin->block() ? Instance::fromArray($view) : new Instance($plugin->block());
        } catch (InvalidArgumentException) {
            $instance = new Instance($plugin->block());
        }

        $html = $this->render(ComponentKind::Block, $instance, 1, $frame->assets, $frame->headings->inArea(null, $instance->id));

        if ($delivery->isFallback()) {
            $language = $delivery->contentLanguage();
            $html = self::withLanguage($html, $language->code, $language->direction === $delivery->language()->direction ? null : $language->direction->value);
        }

        if (!$this->editMode->marking() && !$this->editMode->anchors()) {
            return $html;
        }

        $component = $this->service(Components::class)->get(ComponentKind::Block, $instance->name);
        $component = null === $component ? null : $this->service(Labels::class)->component($component);

        return null === $component ? $html : $this->markup->block($html, $instance, $component, '', fixed: true);
    }

    /**
     * Another block inside the view of the block being rendered, e.g. the
     * form of the forms module below the details of a job (decision
     * 0230): with its controller, styles and scripts, its main heading
     * below the one of the outer block. Editors change it through the
     * options of the outer block, so it gets no marks for editing.
     *
     * @param array<mixed> $options By name, as in the options of a placed block
     *
     * @internal Called by render_block() in templates
     */
    public function inner(Frame $frame, string $name, array $options = [], ?string $variant = null): string
    {
        $options = array_filter($options, \is_string(...), \ARRAY_FILTER_USE_KEY);
        $id = null === $frame->instance->id ? null : $frame->instance->id . '-' . substr(hash('xxh3', $name), 0, 8);

        return $this->render(ComponentKind::Block, new Instance($name, $variant, $options, id: $id), $frame->depth + 1, $frame->assets, $frame->headings->inArea(null, $id), plain: true);
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

            if ($marking && null === $component) {
                $html .= $this->missing($block, $name);

                continue;
            }

            // Labels for the editors in the language of the backend (decision 0161).
            $component = null === $component ? null : $this->service(Labels::class)->component($component);
            $html .= null === $component ? $rendered : $this->markup->block($rendered, $block, $component, $name);
        }

        if (!$marking || '' !== $html) {
            return $html;
        }

        $labels = $this->service(Labels::class);

        return $this->markup->emptyArea($name, $labels->component($frame->component)->areas[$name]->label, $labels->text('Insert a block'));
    }

    /**
     * The placeholder of a block whose extension is gone (decision 0276).
     */
    private function missing(Instance $block, string $area): string
    {
        $labels = $this->service(Labels::class);

        return $this->markup->missing(
            $block,
            $area,
            $labels->text('Missing block'),
            $labels->text('Its extension is not installed. Remove the block or install the extension again.'),
        );
    }

    /**
     * The jump marks of what is about to be rendered, before the first
     * template, so a jump bar at the top knows the marks below it.
     */
    private function collectJumpMarks(Instance $root): void
    {
        $this->service(JumpMarks::class)->collect($root, $this->service(Delivery::class)->contentLanguage()->code);
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

    /**
     * @param bool $plain Without marks for editing or comments, for a block inside another
     */
    private function render(ComponentKind $kind, Instance $instance, int $depth, ?AssetList $assets, Headings $headings, bool $plain = false): string
    {
        $components = $this->service(Components::class);
        $component = $components->get($kind, $instance->name);

        // A page whose layout is gone shows the first one editors may choose (decision 0276).
        if (null === $component && ComponentKind::Layout === $kind) {
            $component = $components->fallbackLayout(null !== $this->service(Delivery::class)->plugin());
        }

        if (null === $component || $depth > self::MAX_DEPTH) {
            return $this->environment->isDevelopment()
                ? '<!-- ' . Html::escape(\sprintf(null === $component ? 'Frootbox: unknown %s %s' : 'Frootbox: %s %s is nested too deeply', $kind->value, $instance->name)) . ' -->'
                : '';
        }

        $assets?->addComponent($component);

        if (!$plain && ($this->editMode->marking() || $this->editMode->anchors())) {
            $component = $this->service(Labels::class)->component($component);
        }

        $markup = match (true) {
            $plain => null,
            $this->editMode->marking() => $this->markup,
            $this->editMode->anchors() => $this->markup->forPreview(),
            default => null,
        };
        $options = $component->variant($instance->variant)->options($instance->options);
        $html = $this->service(Twig::class)->render($component->variant($instance->variant)->template, [
            ...$this->controllerData($component, $instance, $options),
            'id' => $instance->id,
            'options' => $options,
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
     * What the controller of the component adds for its view (decision 0219).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function controllerData(Component $component, Instance $instance, array $options): array
    {
        if (null === $component->controller) {
            return [];
        }

        // Tippen und F5: a new controller works without composer dump-autoload (decision 0073).
        if (!class_exists($component->controller) && null !== $component->controllerFile && is_file($component->controllerFile)) {
            require_once $component->controllerFile;
        }

        if (!class_exists($component->controller)) {
            throw new ViewException(\sprintf('The controller of %s must be the class %s in %s.', $component->name, $component->controller, $component->controllerFile ?? $component->name . '.php'));
        }

        $controller = $this->container->get($component->controller);

        if (!$controller instanceof BlockController) {
            throw new ViewException(\sprintf('%s must implement %s.', $component->controller, BlockController::class));
        }

        $delivery = $this->service(Delivery::class);

        return $controller->data(new BlockContext(
            $instance,
            $options,
            $delivery->language()->code,
            $delivery->contentLanguage()->code,
            $delivery->target(),
            $delivery->query($component->query),
            $delivery->subpage(),
            $component->plugin ? $delivery->plugin() : null,
        ));
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
