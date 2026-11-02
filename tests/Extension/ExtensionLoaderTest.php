<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Extension;

use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Extension\CoreExtension;
use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\ExtensionLoader;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Extension\Source\ComposerSource;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Fixtures\Extensions\ImportNewsCommand;
use Frootbox\Core\Tests\Fixtures\Extensions\NewsExtension;
use Frootbox\Core\Tests\Fixtures\Extensions\NotAnExtension;
use Frootbox\Core\Tests\Support\FakeComposer;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionLoader::class)]
#[CoversClass(ExtensionPackage::class)]
#[CoversClass(Extensions::class)]
#[CoversClass(ComposerSource::class)]
#[CoversClass(Registry::class)]
#[CoversClass(CoreExtension::class)]
#[UsesClass(MigrationSources::class)]
#[UsesClass(ProjectPaths::class)]
final class ExtensionLoaderTest extends TestCase
{
    private TemporaryProject $project;

    private FakeComposer $composer;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->composer = new FakeComposer($this->project);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testFindsOnlyPackagesWithManifest(): void
    {
        $this->composer->add('acme/news', ['title' => 'News', 'description' => 'Articles']);
        $this->composer->add('monolog/monolog', null);

        $extensions = $this->discover();

        self::assertCount(1, $extensions);
        $news = $extensions->get('acme/news');
        self::assertNotNull($news);
        self::assertSame('News', $news->title);
        self::assertSame('Articles', $news->description);
        self::assertFalse($news->isProject);
    }

    public function testTitleDefaultsToThePackageName(): void
    {
        $this->composer->add('acme/news', []);

        self::assertSame('acme/news', $this->discover()->get('acme/news')?->title);
    }

    public function testLoadsDependenciesFirstAndProjectExtensionsLast(): void
    {
        $this->composer->add('acme/site', [], ['vendor/news'], directory: 'extensions/acme-site');
        $this->composer->add('vendor/blog', [], ['vendor/news', 'psr/log']);
        $this->composer->add('vendor/news', []);
        $this->composer->add('vendor/alpha', []);

        $names = array_map(static fn(ExtensionPackage $package): string => $package->name, $this->discover()->packages);

        self::assertSame(['vendor/alpha', 'vendor/news', 'vendor/blog', 'acme/site'], $names);
        self::assertTrue($this->discover()->get('acme/site')?->isProject);
    }

    public function testRejectsCircularDependencies(): void
    {
        $this->composer->add('vendor/a', [], ['vendor/b']);
        $this->composer->add('vendor/b', [], ['vendor/a']);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('in a circle');

        $this->discover();
    }

    public function testRejectsUnknownManifestKeys(): void
    {
        $this->composer->add('vendor/news', ['titel' => 'Typo']);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('unknown keys: titel');

        $this->discover();
    }

    public function testRejectsInvalidYaml(): void
    {
        $path = $this->composer->add('vendor/news', []);
        file_put_contents($path . '/frootbox.yaml', "title: [unclosed\n");

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('not valid YAML');

        $this->discover();
    }

    public function testRejectsWrongTypes(): void
    {
        $this->composer->add('vendor/news', ['title' => ['not', 'text']]);

        $this->expectException(ExtensionException::class);

        $this->discover();
    }

    public function testBootRunsCoreFirstAndThenTheExtensions(): void
    {
        $this->composer->add('vendor/news', ['extension' => NewsExtension::class]);

        $registry = ExtensionLoader::boot($this->discover());

        self::assertArrayHasKey('about', $registry->commands());
        self::assertSame(ImportNewsCommand::class, $registry->commands()['news:import']);
    }

    public function testFindsMigrationsByConvention(): void
    {
        $path = $this->composer->add('vendor/news', [], psr4: ['Vendor\News\Migrations\\' => 'migrations/']);
        mkdir($path . '/migrations');

        $sources = ExtensionLoader::boot($this->discover())->migrationSources()->all();

        self::assertArrayHasKey('Frootbox\Core\Migrations', $sources);
        self::assertSame(realpath($path . '/migrations'), $sources['Vendor\News\Migrations'] ?? null);
    }

    public function testMigrationsNeedANamespace(): void
    {
        $path = $this->composer->add('vendor/news', []);
        mkdir($path . '/migrations');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('autoload.psr-4');

        ExtensionLoader::boot($this->discover());
    }

    public function testExtensionClassMustExist(): void
    {
        $this->composer->add('vendor/news', ['extension' => 'Vendor\News\Missing']);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('does not exist');

        ExtensionLoader::boot($this->discover());
    }

    public function testExtensionClassMustImplementTheInterface(): void
    {
        $this->composer->add('vendor/news', ['extension' => NotAnExtension::class]);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('must implement');

        ExtensionLoader::boot($this->discover());
    }

    public function testCommandNamesMustBeUnique(): void
    {
        $this->composer->add('vendor/news', ['extension' => NewsExtension::class]);
        $registry = ExtensionLoader::boot($this->discover());

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('registered twice');

        $registry->command(ImportNewsCommand::class);
    }

    public function testCachesTheListUntilComposerChangesSomething(): void
    {
        $path = $this->composer->add('vendor/news', ['title' => 'Old']);
        self::assertSame('Old', $this->newsTitle(useCache: true));

        file_put_contents($path . '/frootbox.yaml', "title: New\n");
        self::assertSame('Old', $this->newsTitle(useCache: true));
        self::assertSame('New', $this->newsTitle(useCache: false));

        // composer install or update rewrites installed.json.
        $this->composer->add('vendor/other', null);
        self::assertSame('New', $this->newsTitle(useCache: true));
    }

    public function testWithoutComposerThereAreNoExtensions(): void
    {
        self::assertCount(0, $this->discover());
    }

    private function newsTitle(bool $useCache): ?string
    {
        return $this->discover($useCache)->get('vendor/news')?->title;
    }

    private function discover(bool $useCache = false): Extensions
    {
        $paths = new ProjectPaths($this->project->root);
        $source = new ComposerSource($paths->root . '/vendor', $paths->root . '/extensions');

        return ExtensionLoader::discover([$source], $paths, $useCache);
    }
}
