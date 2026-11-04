<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Extension;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Console\ExtensionCheckCommand;
use Frootbox\Core\Extension\Check\CheckContents;
use Frootbox\Core\Extension\Check\CheckFinding;
use Frootbox\Core\Extension\Check\CheckReport;
use Frootbox\Core\Extension\Check\Dependency;
use Frootbox\Core\Extension\Check\ExtensionChecker;
use Frootbox\Core\Extension\Check\InstalledPackages;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ExtensionChecker::class)]
#[CoversClass(ExtensionCheckCommand::class)]
#[CoversClass(CheckReport::class)]
#[CoversClass(CheckContents::class)]
#[CoversClass(CheckFinding::class)]
#[CoversClass(Dependency::class)]
#[CoversClass(InstalledPackages::class)]
final class ExtensionCheckerTest extends TestCase
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

    public function testFindsWhatAFolderBringsWithoutInstallingIt(): void
    {
        $report = $this->checker()->folder(self::FIXTURE);

        self::assertSame('acme/checked', $report->name);
        self::assertSame(['MIT'], $report->license);
        self::assertSame('^0.1', $report->frootbox);
        self::assertSame(['acme/checked/Teaser'], $report->contents->blocks);
        self::assertSame(['acme/checked/item.archive'], $report->contents->actions);
        self::assertSame(['acme/checked/item.list'], $report->contents->queries);
        self::assertSame(['acme_checked_items', 'checked_log'], $report->contents->tables);
        self::assertSame(['de'], $report->contents->translations);
        self::assertSame(['en'], $report->contents->help);
    }

    public function testATableWithoutThePackageNameIsAnError(): void
    {
        $report = $this->checker()->folder(self::FIXTURE);

        self::assertFalse($report->ok());
        self::assertSame(['naming'], self::rules($report->errors));
        self::assertSame('checked_log', $report->errors[0]->where);
        self::assertStringContainsString('"acme_checked_"', $report->errors[0]->message);
    }

    public function testHintsAtBlocksAndUnknownLicenses(): void
    {
        $report = $this->checker()->folder(self::FIXTURE);
        $hints = array_map(strval(...), $report->warnings);

        self::assertContains('acme/not-installed: It is not installed here, so its license is unknown. [license]', $hints);
        self::assertNotEmpty(array_filter($hints, static fn(string $hint): bool => str_contains($hint, 'acme/checked/Teaser has no group')));
        self::assertContains('frootbox/core', array_map(static fn(Dependency $dependency): string => $dependency->name, $report->dependencies));
    }

    public function testComposerJsonNeedsANameAndALicense(): void
    {
        $folder = $this->package(['description' => 'No name, no license'], "title: Nameless\n");
        $report = $this->checker()->folder($folder);

        self::assertSame(['composer', 'composer'], self::rules($report->errors));
        self::assertStringContainsString('"name"', $report->errors[0]->message);
        self::assertStringContainsString('"license"', $report->errors[1]->message);
    }

    public function testAFolderWithoutManifestIsNoExtension(): void
    {
        $folder = $this->package(['name' => 'acme/plain', 'license' => 'MIT', 'require' => ['frootbox/core' => '^0.1']], null);
        $report = $this->checker()->folder($folder);

        self::assertSame(['manifest'], self::rules($report->errors));
    }

    public function testAMissingExtensionClassIsALoadError(): void
    {
        $folder = $this->package(['name' => 'acme/lost', 'license' => 'proprietary', 'require' => ['frootbox/core' => '^0.1']], "extension: Acme\\Lost\\LostExtension\n");
        $report = $this->checker()->folder($folder);

        self::assertSame(['load'], self::rules($report->errors));
        self::assertStringContainsString('Acme\Lost\LostExtension', $report->errors[0]->message);
    }

    public function testAThemeBringsNoTables(): void
    {
        $folder = $this->package(['name' => 'acme/heavy-theme', 'license' => 'MIT', 'require' => ['frootbox/core' => '^0.1']], "theme: {}\n");
        mkdir($folder . '/migrations');
        $report = $this->checker()->folder($folder);

        self::assertContains('theme', self::rules($report->errors));
    }

    public function testACleanPackageIsFine(): void
    {
        $folder = $this->package(['name' => 'acme/clean', 'license' => 'MIT', 'require' => ['frootbox/core' => '^0.1']], "title: Clean\nicon: icon.svg\n");
        copy(self::FIXTURE . '/icon.svg', $folder . '/icon.svg');
        $report = $this->checker()->folder($folder);

        self::assertTrue($report->ok(strict: true), implode("\n", array_map(strval(...), [...$report->errors, ...$report->warnings])));
    }

    public function testReadsWhatThePackageSaysForTheDirectory(): void
    {
        $listing = $this->checker()->folder(self::FIXTURE)->listing;

        self::assertSame('Checked', $listing->title);
        self::assertSame('A package for the tests of extension:check, with mistakes on purpose.', $listing->description, 'From composer.json without one in the manifest.');
        self::assertStringStartsWith('# Checked', (string) $listing->readme);
        self::assertSame(['items', 'archive'], $listing->keywords);
        self::assertSame('https://example.org/checked', $listing->homepage);
        self::assertSame(['issues' => 'https://example.org/checked/issues'], $listing->support);
        self::assertSame([['name' => 'Acme', 'email' => 'dev@example.org', 'homepage' => null]], $listing->authors);
        self::assertSame('icon.svg', $listing->icon);
        self::assertSame([['file' => 'screenshots/list.png', 'caption' => 'The list of items']], $listing->screenshots);
        self::assertSame(['icon.svg', 'screenshots/list.png'], $listing->files());
    }

    public function testChecksTheFilesForTheDirectory(): void
    {
        $folder = $this->package(['name' => 'acme/pictures', 'license' => 'MIT', 'require' => ['frootbox/core' => '^0.1']], "icon: icon.png\nscreenshots:\n  - ../outside.png\n  - missing.webp\n  - notes.txt\n");
        copy(self::FIXTURE . '/screenshots/list.png', $folder . '/icon.png');
        copy(self::FIXTURE . '/screenshots/list.png', \dirname($folder) . '/outside.png');
        file_put_contents($folder . '/notes.txt', 'Not an image');
        $report = $this->checker()->folder($folder);
        $messages = array_map(strval(...), $report->errors);

        self::assertSame(['listing', 'listing', 'listing', 'listing'], self::rules($report->errors));
        self::assertContains('icon.png: The icon is 8 × 5 pixels, it must be square. [listing]', $messages);
        self::assertContains('../outside.png: The screenshot must be inside the package. [listing]', $messages);
        self::assertContains('missing.webp: The screenshot does not exist. [listing]', $messages);
        self::assertContains('notes.txt: The screenshot must be png, webp, jpg, jpeg. [listing]', $messages);
        self::assertSame([], $report->listing->screenshots);
    }

    public function testCommandPrintsJsonAndFailsOnErrors(): void
    {
        [$exitCode, $output] = $this->console(['target' => self::FIXTURE, '--json' => true]);
        $json = json_decode($output, true);

        self::assertSame(1, $exitCode);
        self::assertIsArray($json);
        self::assertFalse($json['ok']);
        self::assertIsArray($json['packages']);
        self::assertIsArray($json['packages'][0]);
        self::assertSame('acme/checked', $json['packages'][0]['name']);
        self::assertSame([['rule' => 'naming', 'where' => 'checked_log', 'message' => 'The table name must start with "acme_checked_", e.g. "acme_checked_items".']], $json['packages'][0]['errors']);
        self::assertIsArray($json['packages'][0]['listing']);
        self::assertSame('icon.svg', $json['packages'][0]['listing']['icon']);
    }

    public function testStrictTurnsHintsIntoErrors(): void
    {
        $folder = $this->package(['name' => 'acme/loose', 'license' => 'MIT'], "title: Loose\n");

        [$exitCode, $output] = $this->console(['target' => $folder]);
        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('frootbox/core is missing in "require"', $output);

        [$exitCode] = $this->console(['target' => $folder, '--strict' => true]);
        self::assertSame(1, $exitCode);
    }

    public function testCommandNamesAnUnknownTarget(): void
    {
        [$exitCode, $output] = $this->console(['target' => 'acme/nowhere']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('neither a folder nor an installed package', $output);
    }

    public function testCommandChecksAnInstalledPackageByName(): void
    {
        [$exitCode, $output] = $this->console(['target' => 'acme/base']);

        self::assertStringContainsString('acme/base', $output);
        self::assertStringContainsString('composer.json: The file is missing.', $output);
        self::assertSame(1, $exitCode);
    }

    public function testDependenciesAreCopyleftOnlyWithoutAPermissiveChoice(): void
    {
        self::assertTrue(new Dependency('a/b', true, '1.0', ['GPL-3.0-or-later'])->copyleft());
        self::assertTrue(new Dependency('a/b', true, '1.0', ['LGPL-2.1-only', 'MPL-2.0'])->copyleft());
        self::assertFalse(new Dependency('a/b', true, '1.0', ['GPL-2.0-only', 'MIT'])->copyleft());
        self::assertFalse(new Dependency('a/b', true, '1.0', ['Apache-2.0'])->copyleft());
        self::assertFalse(new Dependency('a/b', false)->copyleft());
    }

    private function checker(): ExtensionChecker
    {
        return new ExtensionChecker(new Extensions(), new Config(Environment::Development, ['languages' => ['de' => ['title' => 'Deutsch']]]));
    }

    /**
     * A package folder in the temporary project.
     *
     * @param array<string, mixed> $composer
     */
    private function package(array $composer, ?string $manifest): string
    {
        $folder = $this->project->root . '/var/package-' . bin2hex(random_bytes(4));
        mkdir($folder, 0o777, true);
        file_put_contents($folder . '/composer.json', (string) json_encode($composer));

        if (null !== $manifest) {
            file_put_contents($folder . '/frootbox.yaml', $manifest);
        }

        return $folder;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{int, string}
     */
    private function console(array $arguments): array
    {
        $console = FixtureSite::container($this->project)->get(ConsoleApplication::class);
        self::assertInstanceOf(ConsoleApplication::class, $console);
        $console->setAutoExit(false);
        $output = new BufferedOutput();

        return [$console->run(new ArrayInput(['command' => 'extension:check', ...$arguments]), $output), $output->fetch()];
    }

    /**
     * @param list<CheckFinding> $findings
     *
     * @return list<string>
     */
    private static function rules(array $findings): array
    {
        return array_map(static fn(CheckFinding $finding): string => $finding->rule, $findings);
    }
}
