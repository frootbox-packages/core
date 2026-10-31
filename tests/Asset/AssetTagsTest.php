<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Asset;

use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Asset\AssetTags;
use Frootbox\Core\Asset\PublicAssets;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AssetTags::class)]
#[CoversClass(PublicAssets::class)]
final class AssetTagsTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testLoadsEveryFileOnItsOwnInDevelopment(): void
    {
        [$public, $tags] = $this->services(Environment::Development);

        $html = $tags->styles($this->assets());

        self::assertStringContainsString('@import url("/assets/acme/base/assets/reset.css?v=', $html);
        self::assertStringContainsString('") layer(reset);', $html);
        self::assertStringContainsString('@import url("/assets/acme/site/overrides/acme/base/blocks/Teaser/assets/teaser.css?v=', $html);
        self::assertStringContainsString('<script type="module" src="/assets/acme/base/blocks/Teaser/assets/teaser.js?v=', $tags->scripts($this->assets()));

        $folder = $public->directory('acme/site/overrides/acme/base/blocks/Teaser/assets');
        self::assertTrue(is_link($folder), 'Development links the folder, so changes show at once.');
        self::assertFileExists($folder . '/teaser.css');
        self::assertFileDoesNotExist($public->directory('acme/site/frootbox.yaml'), 'Only assets/ folders are published.');
    }

    public function testBundlesStylesLive(): void
    {
        [$public, $tags] = $this->services(Environment::Production);

        $html = $tags->styles($this->assets());
        self::assertSame(1, preg_match('#^<link rel="stylesheet" href="(/assets/bundles/[0-9a-f]{16}\.css)">$#', $html, $match));
        self::assertSame($html, $tags->styles($this->assets()), 'The same files give the same bundle.');

        $bundle = (string) file_get_contents($this->project->root . '/public' . $match[1]);
        self::assertLessThan(strpos($bundle, '@layer theme {'), strpos($bundle, '@layer reset {'));
        self::assertStringContainsString('.site-teaser { color: red; }', $bundle);
        self::assertStringContainsString('url("/assets/acme/site/assets/img/logo.svg")', $bundle, 'Relative URLs point to the published folder.');
        self::assertStringContainsString('url("data:image/svg+xml,%3Csvg%3E")', $bundle, 'Data URLs stay as they are.');

        $folder = $public->directory('acme/site/assets');
        self::assertFalse(is_link($folder), 'Live the folder is copied.');
        self::assertFileExists($folder . '/img/logo.svg');
    }

    public function testPublishesAllFolders(): void
    {
        [$public] = $this->services(Environment::Production);

        self::assertSame(4, $public->publishAll(), "Two per theme; the tests of the core are no assets.");
        self::assertFileExists($public->directory('acme/base/blocks/Teaser/assets/teaser.js'));
        self::assertFileExists($public->directory('acme/site/overrides/acme/base/blocks/Teaser/assets/extra.css'));
    }

    public function testRefusesFilesOutsideAssetsFolders(): void
    {
        [$public] = $this->services(Environment::Development);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not inside an assets/ folder of an installed package.');

        $public->url(FixtureSite::path('site') . '/frootbox.yaml');
    }

    /**
     * @return array{PublicAssets, AssetTags}
     */
    private function services(Environment $environment): array
    {
        $public = new PublicAssets(FixtureSite::extensions('base', 'site'), new ProjectPaths($this->project->root), $environment);

        return [$public, new AssetTags($public, $environment)];
    }

    private function assets(): AssetList
    {
        $assets = new AssetList();
        $assets->add(FixtureSite::path('site') . '/overrides/acme/base/blocks/Teaser/assets/teaser.css', 'blocks');
        $assets->add(FixtureSite::path('base') . '/blocks/Teaser/assets/teaser.js', 'blocks');
        $assets->add(FixtureSite::path('site') . '/assets/site.css', 'theme');
        $assets->add(FixtureSite::path('base') . '/assets/reset.css', 'reset');

        return $assets;
    }
}
