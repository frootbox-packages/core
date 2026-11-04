<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Extension;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Console\ExtensionCatalogCommand;
use Frootbox\Core\Extension\Check\CatalogWriter;
use Frootbox\Core\Extension\Check\ExtensionChecker;
use Frootbox\Core\Extension\Check\Listing;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(CatalogWriter::class)]
#[CoversClass(ExtensionCatalogCommand::class)]
#[CoversClass(Listing::class)]
final class CatalogWriterTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/Checked';

    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testWritesTheListingAndCopiesItsFiles(): void
    {
        $folder = $this->package('acme/clean', "title: Clean\nicon: icon.svg\nscreenshots:\n  - { file: shots/list.png, caption: The list }\n");
        $out = $this->project->root . '/var/catalog';
        mkdir($out . '/files/acme/clean', 0o777, true);
        file_put_contents($out . '/files/acme/clean/old.png', 'From the last run');

        $result = $this->writer()->write([$folder, self::FIXTURE], $out, '0.2.0');
        $catalog = json_decode((string) file_get_contents($out . '/catalog.json'), true);

        self::assertSame(['acme/clean'], $result['written']);
        self::assertSame(['acme/checked'], array_keys($result['skipped']), 'The fixture has a table without the package name.');
        self::assertIsArray($catalog);
        self::assertSame(1, $catalog['version']);
        self::assertIsArray($catalog['packages']);
        self::assertCount(1, $catalog['packages']);
        $package = $catalog['packages'][0];
        self::assertIsArray($package);
        self::assertSame('acme/clean', $package['name']);
        self::assertSame('0.2.0', $package['version']);
        self::assertSame('Clean', $package['title']);
        self::assertSame('composer require acme/clean', $package['command']);
        self::assertSame('files/acme/clean/icon.svg', $package['icon']);
        self::assertSame([['file' => 'files/acme/clean/shots/list.png', 'caption' => 'The list']], $package['screenshots']);
        self::assertFileEquals(self::FIXTURE . '/icon.svg', $out . '/files/acme/clean/icon.svg');
        self::assertFileExists($out . '/files/acme/clean/shots/list.png');
        self::assertFileDoesNotExist($out . '/files/acme/clean/old.png', 'The files of a package start afresh.');
    }

    public function testCommandNamesWhatItLeftOut(): void
    {
        $console = FixtureSite::container($this->project)->get(ConsoleApplication::class);
        self::assertInstanceOf(ConsoleApplication::class, $console);
        $console->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $console->run(new ArrayInput(['command' => 'extension:catalog', 'folders' => [self::FIXTURE], '--out' => $this->project->root . '/var/catalog']), $output);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('acme/checked is left out', $output->fetch());
        self::assertFileExists($this->project->root . '/var/catalog/catalog.json');
    }

    private function writer(): CatalogWriter
    {
        return new CatalogWriter(new ExtensionChecker(new Extensions(), new Config(Environment::Development, ['languages' => ['de' => ['title' => 'Deutsch']]])));
    }

    private function package(string $name, string $manifest): string
    {
        $folder = $this->project->root . '/var/package-' . bin2hex(random_bytes(4));
        mkdir($folder . '/shots', 0o777, true);
        file_put_contents($folder . '/composer.json', (string) json_encode(['name' => $name, 'license' => 'MIT', 'require' => ['frootbox/core' => '^0.1']]));
        file_put_contents($folder . '/frootbox.yaml', $manifest);
        copy(self::FIXTURE . '/icon.svg', $folder . '/icon.svg');
        copy(self::FIXTURE . '/screenshots/list.png', $folder . '/shots/list.png');

        return $folder;
    }
}
