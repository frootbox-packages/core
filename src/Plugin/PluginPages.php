<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Page\Pages;

/**
 * The published pages that show a space of a plugin (decision 0232), for
 * addresses, links to the list and teasers.
 */
final class PluginPages
{
    /** @var array<string, array{string, PluginPlace}>|null page id and place by "type|space" */
    private ?array $places = null;

    public function __construct(private readonly Pages $pages) {}

    /**
     * The published page that shows the space, null without one.
     */
    public function of(string $type, string $space = Space::DEFAULT): ?string
    {
        return $this->places()[$type . '|' . $space][0] ?? null;
    }

    /**
     * The page for "all" of a type, e.g. for the link "All news" of a
     * teaser over all spaces: the page of the default space, else the
     * first one that shows a space of the type.
     */
    public function main(string $type): ?string
    {
        if (null !== $page = $this->of($type)) {
            return $page;
        }

        foreach ($this->places() as [$page, $place]) {
            if ($place->type === $type) {
                return $page;
            }
        }

        return null;
    }

    /**
     * The place a published page shows, null for none.
     */
    public function at(string $pageId): ?PluginPlace
    {
        return $this->all()[$pageId] ?? null;
    }

    /**
     * The places of all published pages with a plugin.
     *
     * @return array<string, PluginPlace> by page id
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->places() as [$page, $place]) {
            $all[$page] = $place;
        }

        return $all;
    }

    /**
     * Forget what was read, e.g. after publishing pages.
     */
    public function clear(): void
    {
        $this->places = null;
    }

    /**
     * @return array<string, array{string, PluginPlace}>
     */
    private function places(): array
    {
        if (null === $this->places) {
            $this->places = [];

            foreach ($this->pages->fetch(['where' => ['pluginType !=' => null], 'order' => 'id']) as $page) {
                if (null !== $page->pluginType) {
                    $place = new PluginPlace($page->pluginType, $page->pluginSpace ?? Space::DEFAULT, $page->pluginDetails, $page->pluginCategories);
                    $this->places[$place->type . '|' . $place->space] ??= [$page->id, $place];
                }
            }
        }

        return $this->places;
    }
}
