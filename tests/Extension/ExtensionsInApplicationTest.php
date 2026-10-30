<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Extension;

use Frootbox\Core\Application;
use Frootbox\Core\Console\ExtensionsCommand;
use Frootbox\Core\Console\MakeExtensionCommand;
use Frootbox\Core\Container\ContainerFactory;
use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\ExtensionLoader;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Tests\Fixtures\Extensions\NewsExtension;
use Frootbox\Core\Tests\Support\FakeComposer;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * An installation with a real extension: route, listener and command.
 */
#[CoversClass(Application::class)]
#[CoversClass(ContainerFactory::class)]
#[CoversClass(ExtensionLoader::class)]
#[CoversClass(Registry::class)]
#[CoversClass(ExtensionsCommand::class)]
#[CoversClass(MakeExtensionCommand::class)]
final class ExtensionsInApplicationTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create(dotenv: "APP_ENV=dev\n");
        file_put_contents($this->project->root . '/composer.json', "{\n    \"name\": \"acme/project\"\n}\n");
        new FakeComposer($this->project)->add('vendor/news', ['title' => 'News', 'extension' => NewsExtension::class]);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testRoutesAndListenersOfExtensionsWork(): void
    {
        $response = $this->application()->handle(new ServerRequest('GET', '/news'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('news: listener', (string) $response->getBody());
    }

    public function testCommandsOfExtensionsAreAvailable(): void
    {
        self::assertSame([0, "imported\n"], $this->console(['command' => 'news:import']));
    }

    public function testListsTheExtensions(): void
    {
        [$exitCode, $output] = $this->console(['command' => 'extensions']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('vendor/news', $output);
        self::assertStringContainsString('vendor/vendor/news', $output);
    }

    public function testMakeExtensionCreatesAWorkingSkeleton(): void
    {
        [$exitCode, $output] = $this->console(['command' => 'make:extension', 'name' => 'acme/my-site', '--title' => "Acme's Site"]);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('composer require acme/my-site', $output);

        $directory = $this->project->root . '/extensions/acme-my-site';
        $composer = json_decode((string) file_get_contents($directory . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertSame('frootbox-extension', $composer['type']);
        self::assertIsArray($composer['autoload']);
        self::assertSame(['Acme\MySite\\' => 'src/', 'Acme\MySite\Migrations\\' => 'migrations/'], $composer['autoload']['psr-4']);

        self::assertSame("title: 'Acme''s Site'\ndescription: ''\nextension: Acme\\MySite\\MySiteExtension\n", file_get_contents($directory . '/frootbox.yaml'));

        require_once $directory . '/src/MySiteExtension.php';
        self::assertTrue(is_subclass_of('Acme\MySite\MySiteExtension', Extension::class));

        $project = json_decode((string) file_get_contents($this->project->root . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($project);
        self::assertSame([['type' => 'path', 'url' => 'extensions/*']], $project['repositories']);
    }

    public function testMakeExtensionAddsTheRepositoryOnlyOnce(): void
    {
        $this->console(['command' => 'make:extension', 'name' => 'acme/one']);
        $this->console(['command' => 'make:extension', 'name' => 'acme/two']);

        $project = json_decode((string) file_get_contents($this->project->root . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($project);
        self::assertIsArray($project['repositories']);
        self::assertCount(1, $project['repositories']);
    }

    public function testMakeExtensionRejectsInvalidNamesAndExistingFolders(): void
    {
        self::assertSame(2, $this->console(['command' => 'make:extension', 'name' => 'Acme Site'])[0]);

        $this->console(['command' => 'make:extension', 'name' => 'acme/site']);
        self::assertSame(1, $this->console(['command' => 'make:extension', 'name' => 'acme/site'])[0]);
    }

    private function application(): Application
    {
        return Application::fromProjectRoot($this->project->root, []);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{int, string}
     */
    private function console(array $input): array
    {
        $output = new BufferedOutput();
        $arrayInput = new ArrayInput($input);
        $arrayInput->setInteractive(false);

        return [$this->application()->runConsole($arrayInput, $output), $output->fetch()];
    }
}
