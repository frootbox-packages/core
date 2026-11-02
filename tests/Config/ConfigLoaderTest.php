<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Config;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\ConfigLoader;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigLoader::class)]
#[UsesClass(Config::class)]
#[UsesClass(ProjectPaths::class)]
final class ConfigLoaderTest extends TestCase
{
    private ?TemporaryProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->remove();
    }

    public function testReadsYamlAndDefaultsToProduction(): void
    {
        $config = $this->load("app:\n  name: Testsite\n");

        self::assertSame('Testsite', $config->string('app.name'));
        self::assertSame(Environment::Production, $config->environment);
    }

    public function testReadsEnvironmentFromDotenv(): void
    {
        $config = $this->load("app:\n  name: Testsite\n", "APP_ENV=dev\n");

        self::assertSame(Environment::Development, $config->environment);
    }

    public function testServerEnvironmentWinsOverDotenv(): void
    {
        $config = $this->load("app:\n  name: Testsite\n", "APP_ENV=dev\nGREETING=from-file\n", ['APP_ENV' => 'prod']);

        self::assertSame(Environment::Production, $config->environment);
        self::assertSame('from-file', $config->env('GREETING'));
    }

    public function testReadsTheSettingsOfExtensions(): void
    {
        $this->project = TemporaryProject::create("app: {}\n", "SHOP_KEY=abc\n");
        mkdir($this->project->root . '/config/extensions/acme', 0o777, true);
        file_put_contents($this->project->root . '/config/extensions/acme/shop.yaml', "url: https://shop.example\napiKey: '%env(SHOP_KEY)%'\n");

        $config = ConfigLoader::load(new ProjectPaths($this->project->root));

        self::assertSame(['acme/shop' => ['url' => 'https://shop.example', 'apiKey' => 'abc']], $config->get('extensions'));
    }

    public function testRejectsUnknownEnvironment(): void
    {
        $this->expectException(ConfigException::class);

        $this->load("app: {}\n", "APP_ENV=staging\n");
    }

    public function testReplacesEnvPlaceholders(): void
    {
        $config = $this->load("database:\n  url: '%env(DATABASE_URL)%'\n", "DATABASE_URL=sqlite:///test.db\n");

        self::assertSame('sqlite:///test.db', $config->string('database.url'));
    }

    public function testFailsOnMissingEnvVariable(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DATABASE_URL');

        $this->load("database:\n  url: '%env(DATABASE_URL)%'\n");
    }

    public function testFailsWithoutAppYaml(): void
    {
        $this->expectException(ConfigException::class);

        $this->load(null);
    }

    public function testAcceptsEmptyAppYaml(): void
    {
        $config = $this->load('');

        self::assertNull($config->get('app.name'));
    }

    public function testRejectsInvalidYaml(): void
    {
        $this->expectException(ConfigException::class);

        $this->load("app: [unclosed\n");
    }

    /**
     * @param array<string, string> $serverEnvironment
     */
    private function load(?string $appYaml, ?string $dotenv = null, array $serverEnvironment = []): Config
    {
        $this->project = TemporaryProject::create($appYaml, $dotenv);

        return ConfigLoader::load(new ProjectPaths($this->project->root), $serverEnvironment);
    }
}
