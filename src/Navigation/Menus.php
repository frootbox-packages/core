<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Page\PageOutline;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\Delivery;
use Frootbox\Core\View\ViewException;

/**
 * Builds the published navigations and the breadcrumb for the page being
 * shown, in the language of the visitor (decision 0106).
 */
final readonly class Menus
{
    public function __construct(
        private Definitions $definitions,
        private NavigationReader $reader,
        private EditMode $editMode,
        private PageOutline $outline,
        private Urls $urls,
        private Delivery $delivery,
        private Languages $languages,
    ) {}

    /**
     * The items of a navigation. A language without own items uses those
     * of its fallback.
     *
     * @return list<MenuItem>
     *
     * @throws ViewException if the theme has no such navigation
     */
    public function navigation(string $name): array
    {
        $definition = $this->definitions->get($name) ?? throw new ViewException(\sprintf(
            'The theme has no navigation "%s". Declare it in frootbox.yaml under "theme: navigations:". Navigations: %s.',
            $name,
            [] === $this->definitions->all() ? 'none' : implode(', ', array_keys($this->definitions->all())),
        ));
        // In the editing mode the draft, as it will be once published (decision 0119).
        $items = $this->editMode->active() ? $this->reader->current($name) : $this->reader->published($name);

        foreach ($this->chain() as $language) {
            if (isset($items[$language])) {
                $this->urls->preload();

                return $this->level($items[$language], $definition, 1);
            }
        }

        return [];
    }

    /**
     * The way from the start page to the page being shown.
     *
     * @return list<MenuItem>
     */
    public function breadcrumb(): array
    {
        $target = $this->delivery->target();

        if (null === $target || !$target->isPage()) {
            return [];
        }

        $this->urls->preload();
        $items = [];

        foreach ($this->outline->trail($target->id) as $id) {
            $item = $this->page($id, $id, []);

            if (null !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<MenuItem>
     */
    private function level(array $items, Definition $definition, int $depth): array
    {
        $built = [];

        foreach ($items as $item) {
            $options = $this->options($item['options'] ?? [], $definition);

            if ('pages' === ($item['type'] ?? null)) {
                $levels = min(\is_int($item['depth'] ?? null) ? $item['depth'] : 1, $definition->depth - $depth + 1);
                $target = Target::tryParse(\is_string($item['target'] ?? null) ? $item['target'] : '');

                if (null !== $target && $target->isPage()) {
                    array_push($built, ...$this->pages($target->id, $levels, self::string($item, 'id'), $options));
                }

                continue;
            }

            $children = $depth < $definition->depth ? $this->level(NavigationReader::list($item['children'] ?? []), $definition, $depth + 1) : [];
            $menuItem = $this->item($item, $options, $children);

            if (null !== $menuItem) {
                $built[] = $menuItem;
            }
        }

        return $built;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $options
     * @param list<MenuItem>       $children
     */
    private function item(array $item, array $options, array $children): ?MenuItem
    {
        $type = self::string($item, 'type');
        $label = self::string($item, 'label');
        $newWindow = true === ($item['newWindow'] ?? false);
        $target = Target::tryParse(self::string($item, 'target'));
        $url = null;

        // Locked pages are left out (decision 0117).
        if (null !== $target && $target->isPage() && null !== $this->outline->lockedBy($target->id)) {
            return null;
        }

        switch ($type) {
            case 'target':
                if (null === $target) {
                    return null;
                }

                $url = $this->urls->href($target);
                $label = '' === $label && $target->isPage() ? $this->outline->title($target->id, $this->chain()) ?? '' : $label;

                break;

            case 'url':
                $url = self::string($item, 'url');

                break;

            case 'anchor':
                $base = null === $target ? '' : $this->urls->href($target);
                $url = null === $base ? null : $base . '#' . self::string($item, 'anchor');

                break;

            case 'phone':
                $url = 'tel:' . preg_replace('/[^0-9+]/', '', self::string($item, 'phone'));
                $label = '' === $label ? self::string($item, 'phone') : $label;

                break;

            case 'email':
                $url = 'mailto:' . self::string($item, 'email');
                $label = '' === $label ? self::string($item, 'email') : $label;

                break;

            case 'heading':
                if ([] === $children) {
                    return null;
                }

                break;

            default:
                return null;
        }

        if (('heading' !== $type && (null === $url || '' === $url)) || '' === $label) {
            return null;
        }

        // Anchors, phone numbers and e-mail addresses never stand for a page.
        $links = \in_array($type, ['target', 'url'], true);
        $current = $links && $this->isCurrent($url);
        $active = $current || ($links && $this->isBelow($url));

        foreach ($children as $child) {
            $active = $active || $child->active;
        }

        return new MenuItem(self::string($item, 'id'), $type, $label, $url, $current, $active, $newWindow, $options, $children);
    }

    /**
     * The subpages of a page as items, a level deep per depth.
     *
     * @param array<string, mixed> $options
     *
     * @return list<MenuItem>
     */
    private function pages(string $parentId, int $levels, string $prefix, array $options): array
    {
        $items = [];

        foreach ($this->outline->children($parentId) as $id) {
            $children = $levels > 1 ? $this->pages($id, $levels - 1, $prefix, $options) : [];
            $item = $this->page($id, $prefix . '-' . $id, $options, $children);

            if (null !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A page as an item, null if it has no address in the language or is
     * locked.
     *
     * @param array<string, mixed> $options
     * @param list<MenuItem>       $children
     */
    private function page(string $id, string $itemId, array $options, array $children = []): ?MenuItem
    {
        $url = $this->urls->href(Target::page($id));
        $title = $this->outline->title($id, $this->chain());

        if (null === $url || null === $title || null !== $this->outline->lockedBy($id)) {
            return null;
        }

        $current = $this->isCurrent($url);
        $active = $current || $this->isBelow($url);

        foreach ($children as $child) {
            $active = $active || $child->active;
        }

        return new MenuItem($itemId, 'target', $title, $url, $current, $active, false, $options, $children);
    }

    /**
     * The options of the navigation with their defaults.
     *
     * @return array<string, mixed>
     */
    private function options(mixed $values, Definition $definition): array
    {
        $values = \is_array($values) ? $values : [];
        $options = [];

        foreach ($definition->options as $name => $option) {
            $options[$name] = $option->clean($values[$name] ?? null);
        }

        return $options;
    }

    private function isCurrent(?string $url): bool
    {
        $current = $this->currentUrl();

        return null !== $url && null !== $current && $current === $url;
    }

    /**
     * Whether the page being shown lies below the URL, the start page of
     * a language aside.
     */
    private function isBelow(?string $url): bool
    {
        $current = $this->currentUrl();
        $root = $this->delivery->language()->path('');

        return null !== $url && null !== $current && $url !== $root && str_starts_with($current, rtrim($url, '/') . '/');
    }

    private function currentUrl(): ?string
    {
        $target = $this->delivery->target();

        return null === $target ? null : $this->urls->href($target);
    }

    /**
     * The language of the visitor and its fallbacks.
     *
     * @return non-empty-list<string>
     */
    private function chain(): array
    {
        return $this->languages->contentLanguages($this->delivery->language()->code);
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function string(array $item, string $key): string
    {
        return \is_string($item[$key] ?? null) ? $item[$key] : '';
    }
}
