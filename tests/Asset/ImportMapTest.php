<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Asset;

use Frootbox\Core\Asset\ImportMap;
use Frootbox\Core\Asset\PublicAssets;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportMap::class)]
final class ImportMapTest extends TestCase
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

    public function testWritesTheImportsOfAllPackages(): void
    {
        $registry = new Registry();
        $map = new ImportMap($registry, new PublicAssets(FixtureSite::extensions('base'), new ProjectPaths($this->project->root), Environment::Development));
        self::assertSame('', $map->tag(), 'Without imports there is no map.');

        $registry->forPackage('acme/base', static fn() => $registry->import('teaser', 'blocks/Teaser/assets/teaser.js'), FixtureSite::path('base'));

        self::assertMatchesRegularExpression('#^<script type="importmap">\{"imports":\{"teaser":"/assets/acme/base/blocks/Teaser/assets/teaser\.js\?v=\d+"\}\}</script>$#', $map->tag());
        self::assertMatchesRegularExpression('#^<script type="module" src="/assets/acme/base/blocks/Teaser/assets/teaser\.js\?v=\d+"></script>$#', $map->module(FixtureSite::path('base') . '/blocks/Teaser/assets/teaser.js'));
    }
}
