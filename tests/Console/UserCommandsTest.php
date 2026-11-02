<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Console;

use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Console\UserCreateCommand;
use Frootbox\Core\Console\UserTokenCommand;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Frootbox\Core\User\Users;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(UserCreateCommand::class)]
#[CoversClass(UserTokenCommand::class)]
final class UserCommandsTest extends TestCase
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

    public function testCreatesAnAdministratorWithAPassword(): void
    {
        QuestionHelper::disableStty();
        [$exitCode, $output] = $this->console(['command' => 'user:create', 'email' => 'jane@example.com', '--role' => ['admin']], "correct horse battery\ncorrect horse battery\n");

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('jane@example.com with the role admin', $output);

        $users = $this->application->container()->get(Users::class);
        self::assertInstanceOf(Users::class, $users);
        $user = $users->findByEmail('jane@example.com');
        self::assertSame('jane', $user?->name);
        self::assertTrue(password_verify('correct horse battery', $users->passwordHash($user->id) ?? ''));
    }

    public function testRefusesMismatchingAndShortPasswords(): void
    {
        QuestionHelper::disableStty();

        [$exitCode, $output] = $this->console(['command' => 'user:create', 'email' => 'jane@example.com'], "correct horse battery\nsomething else\n");
        self::assertSame(2, $exitCode);
        self::assertStringContainsString('do not match', $output);

        [$exitCode, $output] = $this->console(['command' => 'user:create', 'email' => 'jane@example.com'], "short\nshort\n");
        self::assertSame(2, $exitCode);
        self::assertStringContainsString('password', $output);
    }

    public function testWithoutAPasswordItNeedsTheFlagWhenNotInteractive(): void
    {
        [$exitCode, $output] = $this->console(['command' => 'user:create', 'email' => 'jane@example.com']);
        self::assertSame(2, $exitCode);
        self::assertStringContainsString('--no-password', $output);

        [$exitCode] = $this->console(['command' => 'user:create', 'email' => 'jane@example.com', '--name' => 'Jane Doe', '--no-password' => true]);
        self::assertSame(0, $exitCode);
    }

    public function testCreatesAnApiToken(): void
    {
        $this->console(['command' => 'user:create', 'email' => 'jane@example.com', '--no-password' => true]);

        [$exitCode, $output] = $this->console(['command' => 'user:token', 'email' => 'jane@example.com', '--name' => 'Deploy', '--days' => '7']);
        self::assertSame(0, $exitCode, $output);
        self::assertSame(1, preg_match('/^(fbx_[0-9a-f]{64})$/m', $output, $match));

        $tokens = $this->application->container()->get(AccessTokens::class);
        self::assertInstanceOf(AccessTokens::class, $tokens);
        self::assertSame('jane', $tokens->authenticate($match[1])?->actor->name);

        self::assertSame(1, $this->console(['command' => 'user:token', 'email' => 'nobody@example.com'])[0]);
        self::assertSame(2, $this->console(['command' => 'user:token', 'email' => 'jane@example.com', '--days' => 'soon'])[0]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function console(array $input, ?string $answers = null): array
    {
        $output = new BufferedOutput();
        $arrayInput = new ArrayInput($input);
        $arrayInput->setInteractive(null !== $answers);

        if (null !== $answers) {
            $stream = fopen('php://memory', 'r+');
            self::assertIsResource($stream);
            fwrite($stream, $answers);
            rewind($stream);
            $arrayInput->setStream($stream);
        }

        return [$this->application->runConsole($arrayInput, $output), $output->fetch()];
    }
}
