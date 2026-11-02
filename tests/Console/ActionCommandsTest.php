<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Console;

use Frootbox\Core\Application;
use Frootbox\Core\Console\ActionLogCommand;
use Frootbox\Core\Console\ActionsCommand;
use Frootbox\Core\Console\CallCommand;
use Frootbox\Core\Console\PruneActionLogCommand;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ActionsCommand::class)]
#[CoversClass(CallCommand::class)]
#[CoversClass(ActionLogCommand::class)]
#[CoversClass(PruneActionLogCommand::class)]
#[CoversClass(Application::class)]
final class ActionCommandsTest extends TestCase
{
    private TemporaryProject $project;

    private Application $application;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create(
            "database:\n  url: 'pdo-sqlite:///storage/database.sqlite'\n",
            "APP_ENV=dev\n",
        );
        $this->application = Application::fromProjectRoot($this->project->root, []);
        $this->console(['command' => 'migrate']);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListsActionsAndShowsTheirSchema(): void
    {
        [$exitCode, $output] = $this->console(['command' => 'actions']);
        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('action.undo', $output);

        [, $output] = $this->console(['command' => 'actions', 'name' => 'action.undo']);
        self::assertStringContainsString('"required": [', $output);
    }

    public function testCallRunsActionsAsTheSystem(): void
    {
        [$exitCode, $output] = $this->console(['command' => 'call', 'name' => 'action.undo', 'input' => '{"id": 42}']);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('no such entry', $output);

        [$exitCode, $output] = $this->console(['command' => 'call', 'name' => 'action.undo', 'input' => '{}']);
        self::assertSame(2, $exitCode);
        self::assertStringContainsString('/id: is required.', $output);

        self::assertSame(2, $this->console(['command' => 'call', 'name' => 'action.undo', 'input' => '[1]'])[0]);

        [$exitCode, $output] = $this->console(['command' => 'actions:log']);
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('failed', $output);
        self::assertStringContainsString('invalid', $output);
        self::assertStringContainsString('System', $output);
    }

    public function testPrunesTheLog(): void
    {
        [$exitCode, $output] = $this->console(['command' => 'actions:prune']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('older than 30 days', $output);
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

        return [$this->application->runConsole($arrayInput, $output), $output->fetch()];
    }
}
