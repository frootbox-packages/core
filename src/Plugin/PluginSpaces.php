<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Page\PageReader;

/**
 * The spaces of the plugins and the pages that show them (decision 0232).
 * The default space of each type exists without a row, its name comes
 * from the plugin or from space_defaults (decision 0246).
 */
final readonly class PluginSpaces
{
    public function __construct(
        private Spaces $spaces,
        private SpaceDefaults $defaults,
        private Plugins $plugins,
        private PageReader $pages,
        private Translator $translator,
    ) {}

    /**
     * The spaces of a type, the default one first.
     *
     * @return list<SpaceSummary>
     */
    public function all(string $type): array
    {
        $plugin = $this->plugins->get($type);

        if (null === $plugin) {
            return [];
        }

        $pages = $this->pages();
        // The default space is named after the plugin unless an admin renamed it (decision 0246).
        $title = $this->defaults->ofType($type)->title ?? $this->translator->get($plugin->label());
        $spaces = [new SpaceSummary(Space::DEFAULT, $title, true, $pages[$type . '|' . Space::DEFAULT] ?? null)];

        foreach ($this->spaces->ofType($type) as $space) {
            $spaces[] = new SpaceSummary($space->id, $space->title, false, $pages[$type . '|' . $space->id] ?? null);
        }

        return $spaces;
    }

    public function exists(string $type, string $space): bool
    {
        if (null === $this->plugins->get($type)) {
            return false;
        }

        return Space::DEFAULT === $space || $this->spaces->fetchById($space)?->type === $type;
    }

    /**
     * The name of a space for editors, null if it does not exist.
     */
    public function title(string $type, string $space): ?string
    {
        foreach ($this->all($type) as $summary) {
            if ($summary->id === $space) {
                return $summary->title;
            }
        }

        return null;
    }

    /**
     * The spaces of a type as choices of a block option, e.g. for a
     * teaser: "All" first, then each space, if there is more than one.
     *
     * @return list<array{value: string, label: string}>
     */
    public function choices(string $type): array
    {
        $spaces = $this->all($type);

        if (\count($spaces) < 2) {
            return [];
        }

        return [
            ['value' => '', 'label' => $this->translator->get('All')],
            ...array_map(static fn(SpaceSummary $space): array => ['value' => $space->id, 'label' => $space->title], $spaces),
        ];
    }

    /**
     * The page that shows a space, published state first, then drafts.
     */
    public function pageOf(string $type, string $space): ?string
    {
        return $this->pages()[$type . '|' . $space] ?? null;
    }

    /**
     * @return array<string, string> page id by "type|space"
     */
    private function pages(): array
    {
        $pages = [];

        foreach ($this->pages->nodes() as $id => $node) {
            $place = $node->data->plugin;

            if (null !== $place) {
                $pages[$place->type . '|' . $place->space] ??= $id;
            }
        }

        return $pages;
    }
}
