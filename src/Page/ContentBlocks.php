<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Component\Area;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Support\Message;

/**
 * The blocks in the content of one translation of a page, for the
 * actions that arrange them (decision 0123). Blocks are found by their
 * id, places by parent block, area and the block they come after, so a
 * change of someone else elsewhere does not move the target.
 *
 * A way leads from the content to a block, e.g. ["areas", "main", 1,
 * "areas", "left", 0].
 */
final readonly class ContentBlocks
{
    /**
     * @param array<string, mixed> $content
     */
    private function __construct(
        private string $action,
        private Components $components,
        private string $layout,
        public array $content,
    ) {}

    /**
     * The content of the translation, or an input error if there is none.
     */
    public static function of(string $action, Components $components, PageData $page, string $language): self
    {
        $translation = $page->translations[$language] ?? throw new InvalidInputException($action, ['/language' => 'The page has no translation in this language.']);

        return new self($action, $components, $page->layout, $translation->content);
    }

    /**
     * @param array<mixed> $content
     */
    public function with(array $content): self
    {
        $keyed = [];

        foreach ($content as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return new self($this->action, $this->components, $this->layout, $keyed);
    }

    /**
     * Saves the content as the draft of the translation.
     */
    public function save(PageEditor $editor, string $id, PageData $page, string $language): PageDetails
    {
        $translations = $page->translations;
        $translation = $translations[$language];
        $translations[$language] = new TranslationData($translation->title, $translation->slug, $this->content, $translation->description);

        return $editor->saveDraft($this->action, $id, $page->with(translations: $translations));
    }

    /**
     * The way to the block with this id.
     *
     * @return list<string|int>
     */
    public function find(string $id, string $key = '/block'): array
    {
        return self::locate($this->content, $id) ?? $this->fail($key, 'There is no block with this id in the content.');
    }

    /**
     * Fails for the view of a plugin: it stays where the layout puts it,
     * only an admin takes it away with the plugin (decision 0232).
     *
     * @param list<string|int> $way
     */
    public function assertPlaced(array $way): void
    {
        if ('plugin' === ($way[0] ?? null)) {
            $this->fail('/block', 'The view of the plugin stays at its place. It goes with the plugin of the page.');
        }
    }

    /**
     * The block at the way.
     *
     * @param list<string|int> $way
     *
     * @return array<string, mixed>
     */
    public function block(array $way): array
    {
        $block = self::at($this->content, $way);

        if (!\is_array($block)) {
            return [];
        }

        $result = [];

        foreach ($block as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * The component of the block at the way, the layout for the empty way.
     *
     * @param list<string|int> $way
     */
    public function component(array $way): ?Component
    {
        if ([] === $way) {
            return $this->components->get(ComponentKind::Layout, $this->layout);
        }

        $name = $this->block($way)['block'] ?? null;

        return \is_string($name) ? $this->components->block($name) : null;
    }

    /**
     * Where a block goes: into the area of a parent block (null for the
     * layout), after a block of that area (null for the start).
     *
     * @return array{list<string|int>, int} the way to the area's list and the index
     */
    public function place(?string $parent, string $area, ?string $after): array
    {
        $way = null === $parent ? [] : $this->find($parent, '/parent');
        $component = $this->component($way);

        if (null === $component || !isset($component->areas[$area])) {
            $this->fail('/area', new Message('There is no area "{area}" there.', ['area' => $area]));
        }

        $list = [...$way, 'areas', $area];
        $blocks = self::at($this->content, $list);
        $blocks = \is_array($blocks) ? array_values($blocks) : [];

        if (null === $after) {
            return [$list, 0];
        }

        foreach ($blocks as $index => $block) {
            if (\is_array($block) && $after === ($block['id'] ?? null)) {
                return [$list, $index + 1];
            }
        }

        $this->fail('/after', 'There is no block with this id in the area.');
    }

    /**
     * Fails if the area at the end of the list does not take the block.
     *
     * @param list<string|int> $list The way to the area's list
     */
    public function checkAllowed(array $list, string $name): void
    {
        $area = $this->area($list);
        $component = $this->components->block($name) ?? $this->fail('/name', new Message('There is no block "{block}".', ['block' => $name]));

        if (null !== $area && !$area->allows($name, $component->widget)) {
            $this->fail('/area', null === $area->allow
                ? new Message('"{block}" is a widget and only goes into areas that allow it by name.', ['block' => $component->title])
                : new Message('The area "{area}" does not take "{block}".', ['area' => $area->label, 'block' => $component->title]));
        }
    }

    /**
     * Fails if the themes do not offer the block for adding (decision
     * 0162); blocks already on the page stay.
     */
    public function checkOffered(string $name): void
    {
        $component = $this->components->block($name);

        if (null !== $component && !$this->components->offers(ComponentKind::Block, $name)) {
            $this->fail('/name', new Message('The theme does not offer "{block}" for new blocks.', ['block' => $component->title]));
        }
    }

    /**
     * @param list<string|int> $list  The way to the area's list
     * @param array<mixed>     $block
     */
    public function insert(array $list, int $index, array $block): self
    {
        $blocks = self::at($this->content, $list);
        $blocks = \is_array($blocks) ? array_values($blocks) : [];
        array_splice($blocks, $index, 0, [$block]);

        return $this->with(self::put($this->content, $list, $blocks));
    }

    /**
     * @param list<string|int> $way
     */
    public function remove(array $way): self
    {
        $list = \array_slice($way, 0, -1);
        $blocks = self::at($this->content, $list);
        $blocks = \is_array($blocks) ? array_values($blocks) : [];
        array_splice($blocks, (int) end($way), 1);

        return $this->with(self::put($this->content, $list, $blocks));
    }

    /**
     * @param list<string|int>     $way
     * @param array<string, mixed> $block
     */
    public function replace(array $way, array $block): self
    {
        return $this->with(self::put($this->content, $way, $block));
    }

    /**
     * The id of the block at the index of the list, after saving.
     *
     * @param array<string, mixed> $content
     * @param list<string|int>     $list
     */
    public static function idAt(array $content, array $list, int $index): ?string
    {
        $id = self::at($content, [...$list, $index, 'id']);

        return \is_string($id) ? $id : null;
    }

    /**
     * A block and the ones inside it without ids, so saving gives new ones.
     *
     * @param array<mixed> $block
     *
     * @return array<mixed>
     */
    public static function withoutIds(array $block): array
    {
        unset($block['id']);

        if (\is_array($block['areas'] ?? null)) {
            foreach ($block['areas'] as $area => $blocks) {
                $block['areas'][$area] = \is_array($blocks) ? array_map(static fn(mixed $inner): mixed => \is_array($inner) ? self::withoutIds($inner) : $inner, $blocks) : $blocks;
            }
        }

        return $block;
    }

    /**
     * The way to the block with this id, e.g. ["areas", "main", 1].
     *
     * @param array<mixed> $node
     *
     * @return list<string|int>|null
     */
    public static function locate(array $node, string $id): ?array
    {
        // The view of a plugin outside the areas (decision 0232).
        $plugin = $node['plugin'] ?? null;

        if (\is_array($plugin) && $id === ($plugin['id'] ?? null)) {
            return ['plugin'];
        }

        foreach (\is_array($node['areas'] ?? null) ? $node['areas'] : [] as $area => $blocks) {
            foreach (\is_array($blocks) ? $blocks : [] as $index => $block) {
                if (!\is_array($block)) {
                    continue;
                }

                if ($id === ($block['id'] ?? null)) {
                    return ['areas', $area, $index];
                }

                $inner = self::locate($block, $id);

                if (null !== $inner) {
                    return ['areas', $area, $index, ...$inner];
                }
            }
        }

        return null;
    }

    /**
     * @param list<string|int> $keys
     */
    public static function at(mixed $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }

            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @param list<string|int> $list The way to the area's list
     */
    private function area(array $list): ?Area
    {
        $component = $this->component(\array_slice($list, 0, -2));
        $name = end($list);

        return null === $component ? null : $component->areas[(string) $name] ?? null;
    }

    /**
     * @param array<mixed>     $data
     * @param list<string|int> $keys
     *
     * @return array<mixed>
     */
    private static function put(array $data, array $keys, mixed $value): array
    {
        $key = array_shift($keys);

        if (null === $key) {
            return \is_array($value) ? $value : $data;
        }

        $data[$key] = [] === $keys ? $value : self::put(\is_array($data[$key] ?? null) ? $data[$key] : [], $keys, $value);

        return $data;
    }

    private function fail(string $pointer, string|Message $message): never
    {
        throw new InvalidInputException($this->action, [$pointer => $message]);
    }
}
