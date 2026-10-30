<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Console;

use Frootbox\Core\Application;
use Frootbox\Core\Console\AboutCommand;
use Frootbox\Core\Console\ConsoleFactory;
use Frootbox\Core\Console\MigrateCommand;
use Frootbox\Core\Database\ConnectionFactory;
use Frootbox\Core\Database\MigrationsFactory;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\Frootbox;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ConsoleFactory::class)]
#[CoversClass(AboutCommand::class)]
#[CoversClass(MigrateCommand::class)]
#[CoversClass(Application::class)]
#[CoversClass(ConnectionFactory::class)]
#[CoversClass(MigrationsFactory::class)]
#[CoversClass(MigrationSources::class)]
#[CoversClass(Migrator::class)]
final class ConsoleTest extends TestCase
{
    private TemporaryProject $project;

    private Application $application;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create(
            "app:\n  name: Testsite\ndatabase:\n  url: '%env(DATABASE_URL)%'\n",
            "APP_ENV=dev\nDATABASE_URL=pdo-sqlite:///storage/database.sqlite\n",
        );
        $this->application = Application::fromProjectRoot($this->project->root, []);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testMigrateCreatesTheMigrationsTable(): void
    {
        [$exitCode, $output] = $this->console('migrate');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('up to date', $output);
        self::assertFileExists($this->project->root . '/storage/database.sqlite');
    }

    public function testAboutShowsVersionAndDatabase(): void
    {
        [$exitCode, $output] = $this->console('about');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString(Frootbox::VERSION, $output);
        self::assertStringContainsString('pdo_sqlite', $output);
        self::assertStringContainsString('connected', $output);
    }

    public function testDoctrineCommandsAreAvailable(): void
    {
        [$exitCode, $output] = $this->console('list', ['namespace' => 'migrations']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('migrations:status', $output);
    }

    /**
     * @param array<string, string> $arguments
     *
     * @return array{int, string}
     */
    private function console(string $command, array $arguments = []): array
    {
        $output = new BufferedOutput();
        $input = new ArrayInput(['command' => $command, ...$arguments]);
        $input->setInteractive(false);

        return [$this->application->runConsole($input, $output), $output->fetch()];
    }
}
