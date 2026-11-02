<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Asset;

use Frootbox\Core\Asset\Asset;
use Frootbox\Core\Asset\AssetList;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssetList::class)]
#[CoversClass(Asset::class)]
final class AssetListTest extends TestCase
{
    public function testSortsStylesByLayerAndKeepsEachFileOnce(): void
    {
        $assets = new AssetList();
        $assets->add('/a/teaser.css', 'blocks');
        $assets->add('/a/theme.css', 'theme');
        $assets->add('/a/layout.css', 'layouts');
        $assets->add('/a/hero.css', 'blocks');
        $assets->add('/a/teaser.css', 'theme');
        $assets->add('/a/teaser.js', 'blocks');
        $assets->add('/a/logo.svg', 'blocks');

        self::assertSame(
            [['/a/layout.css', 'layouts'], ['/a/teaser.css', 'blocks'], ['/a/hero.css', 'blocks'], ['/a/theme.css', 'theme']],
            array_map(static fn(Asset $asset): array => [$asset->file, $asset->layer], $assets->styles()),
        );
        self::assertSame(['/a/teaser.js'], array_map(static fn(Asset $asset): string => $asset->file, $assets->scripts()));
        self::assertSame('@layer reset, tokens, base, layouts, blocks, theme, project;', new AssetList()->layerOrder());
    }

    public function testRefusesUnknownLayers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"components" is no layer.');

        new AssetList()->add('/a/x.css', 'components');
    }

    public function testTakesOtherLayers(): void
    {
        $assets = new AssetList(['reset', 'components', 'screens']);
        $assets->add('/a/list.css', 'screens');
        $assets->add('/a/button.css', 'components');

        self::assertSame(['/a/button.css', '/a/list.css'], array_map(static fn(Asset $asset): string => $asset->file, $assets->styles()));
        self::assertSame('@layer reset, components, screens;', $assets->layerOrder());
    }
}
