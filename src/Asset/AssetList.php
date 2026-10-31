<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Asset;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentKind;
use InvalidArgumentException;

/**
 * The styles and scripts one page needs, collected while it is rendered.
 * Each file comes once; its layer depends on where it comes from, so
 * authors write plain CSS and the order never depends on loading. The
 * website uses these layers, the backend has its own (decision 0114):
 *
 *     @layer reset, tokens, base, layouts, blocks, theme, project;
 */
final class AssetList
{
    /** The layers of the website. */
    public const array LAYERS = ['reset', 'tokens', 'base', 'layouts', 'blocks', 'theme', 'project'];

    /** @var array<string, Asset> by file */
    private array $assets = [];

    /**
     * @param non-empty-list<string> $layers in cascade order
     */
    public function __construct(private readonly array $layers = self::LAYERS) {}

    /**
     * Adds a .css or .js file; other files such as images are ignored.
     */
    public function add(string $file, string $layer): void
    {
        if (!\in_array($layer, $this->layers, true)) {
            throw new InvalidArgumentException(\sprintf('"%s" is no layer. Layers: %s.', $layer, implode(', ', $this->layers)));
        }

        $asset = new Asset($file, $layer);

        if ($asset->isStyle() || $asset->isScript()) {
            $this->assets[$file] ??= $asset;
        }
    }

    /**
     * The files in assets/ of a rendered block or layout.
     */
    public function addComponent(Component $component): void
    {
        foreach ($component->assets as $file) {
            $this->add($file, ComponentKind::Layout === $component->kind ? 'layouts' : 'blocks');
        }
    }

    /**
     * @return list<Asset> in layer order, then in the order they were added
     */
    public function styles(): array
    {
        $styles = array_values(array_filter($this->assets, static fn(Asset $asset): bool => $asset->isStyle()));
        $order = array_flip($this->layers);
        $positions = array_flip(array_map(static fn(Asset $asset): string => $asset->file, $styles));
        usort($styles, static fn(Asset $a, Asset $b): int => [$order[$a->layer], $positions[$a->file]] <=> [$order[$b->layer], $positions[$b->file]]);

        return $styles;
    }

    /**
     * @return list<Asset> in the order they were added
     */
    public function scripts(): array
    {
        return array_values(array_filter($this->assets, static fn(Asset $asset): bool => $asset->isScript()));
    }

    /**
     * The statement that fixes the order of the layers, first in a page.
     */
    public function layerOrder(): string
    {
        return '@layer ' . implode(', ', $this->layers) . ';';
    }
}
