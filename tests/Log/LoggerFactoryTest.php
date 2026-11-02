<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Log;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Log\LoggerFactory;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoggerFactory::class)]
#[UsesClass(Config::class)]
#[UsesClass(ProjectPaths::class)]
final class LoggerFactoryTest extends TestCase
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

    public function testDevelopmentWritesEverything(): void
    {
        $logger = $this->logger(Environment::Development);
        $logger->debug('Rendering {page}', ['page' => 'home']);
        $logger->close();

        self::assertStringContainsString('frootbox.DEBUG: Rendering home', $this->project->log('dev'));
    }

    public function testProductionWritesWarningsRightAway(): void
    {
        $logger = $this->logger(Environment::Production);
        $logger->info('Cache warmed');
        $logger->warning('Disk almost full');
        $logger->close();

        $log = $this->project->log('prod');

        self::assertStringContainsString('Disk almost full', $log);
        self::assertStringNotContainsString('Cache warmed', $log);
    }

    public function testProductionAddsContextWhenAnErrorOccurs(): void
    {
        $logger = $this->logger(Environment::Production);
        $logger->debug('Loading page 7');
        $logger->error('Rendering failed', ['exception' => new \RuntimeException('Template missing')]);
        $logger->close();

        $log = $this->project->log('prod');

        self::assertStringContainsString('Loading page 7', $log);
        self::assertStringContainsString('Rendering failed', $log);
        self::assertStringContainsString('Template missing', $log);
        self::assertStringContainsString('[stacktrace]', $log);
    }

    public function testLevelCanBeConfigured(): void
    {
        $logger = $this->logger(Environment::Development, ['log' => ['level' => 'warning']]);
        $logger->info('Hidden');
        $logger->warning('Shown');
        $logger->close();

        $log = $this->project->log('dev');

        self::assertStringContainsString('Shown', $log);
        self::assertStringNotContainsString('Hidden', $log);
    }

    public function testRejectsUnknownLevels(): void
    {
        $this->expectException(ConfigException::class);

        $this->logger(Environment::Development, ['log' => ['level' => 'loud']]);
    }

    public function testFallsBackToThePhpErrorLog(): void
    {
        // A file where the directory should be: var/log cannot be created.
        file_put_contents($this->project->root . '/var/log', '');

        self::assertInstanceOf(ErrorLogHandler::class, $this->logger(Environment::Development)->getHandlers()[0]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function logger(Environment $environment, array $settings = []): Logger
    {
        return LoggerFactory::create(new Config($environment, $settings), new ProjectPaths($this->project->root));
    }
}
