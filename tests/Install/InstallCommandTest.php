<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Install;

use Frootbox\Core\Account\SignIn;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Application;
use Frootbox\Core\Install\InstallCommand;
use Frootbox\Core\Install\Installer;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Frootbox\Core\User\RoleAssignments;
use Frootbox\Core\User\Users;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(InstallCommand::class)]
#[CoversClass(Installer::class)]
#[CoversClass(Application::class)]
final class InstallCommandTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        // Like skeleton/: the database comes from .env, which does not exist yet.
        $this->project = TemporaryProject::create("app:\n  name: Testsite\n\ndatabase:\n  url: '%env(DATABASE_URL)%'\n");
        copy(\dirname(__DIR__, 4) . '/skeleton/.env.example', $this->project->root . '/.env.example');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testInstallsWithoutQuestions(): void
    {
        [$exitCode, $output] = $this->install(['--admin-email' => 'jane@example.com']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Frootbox is installed', $output);

        $dotenv = (string) file_get_contents($this->project->root . '/.env');
        self::assertStringContainsString("APP_ENV=prod\n", $dotenv);
        self::assertStringContainsString("DATABASE_URL='" . Installer::DEFAULT_DATABASE_URL . "'", $dotenv);
        self::assertStringContainsString('# APP_ENV', $dotenv, 'The comments of .env.example are kept.');
        self::assertMatchesRegularExpression('/^APP_SECRET=[0-9a-f]{64}$/m', $dotenv);
        self::assertSame(0o600, fileperms($this->project->root . '/.env') & 0o777);
        self::assertFileExists($this->project->root . '/storage/database.sqlite');

        // The generated password is shown once and works.
        self::assertSame(1, preg_match('/Password: (\S+)/', $output, $match));
        $this->assertSignsIn('jane@example.com', $match[1]);

        $application = Application::fromProjectRoot($this->project->root, []);
        $user = $this->users($application)->findByEmail('jane@example.com');
        self::assertSame('jane', $user?->name);
        $assignments = $application->container()->get(RoleAssignments::class);
        self::assertInstanceOf(RoleAssignments::class, $assignments);
        self::assertSame([['role' => 'superuser', 'scope' => null]], $assignments->rolesOf($user->id));
    }

    public function testTakesThePasswordFromTheEnvironment(): void
    {
        [$exitCode, $output] = $this->install(['--admin-email' => 'jane@example.com', '--admin-name' => 'Jane Doe', '--env' => 'dev'], env: [InstallCommand::PASSWORD_VARIABLE => 'correct horse battery']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringNotContainsString('Password:', $output);
        self::assertStringContainsString("APP_ENV=dev\n", (string) file_get_contents($this->project->root . '/.env'));
        $this->assertSignsIn('jane@example.com', 'correct horse battery');
    }

    public function testAsksWhenInteractive(): void
    {
        QuestionHelper::disableStty();

        [$exitCode, $output] = $this->install([], "dev\nsqlite\njane@example.com\n\nshort and wrong\nsomething else\ncorrect horse battery\ncorrect horse battery\n");

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('do not match', $output);
        $this->assertSignsIn('jane@example.com', 'correct horse battery');
    }

    public function testRefusesASecondInstallation(): void
    {
        $this->install(['--admin-email' => 'jane@example.com']);
        $dotenv = (string) file_get_contents($this->project->root . '/.env');

        [$exitCode, $output] = $this->install(['--admin-email' => 'john@example.com']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('already installed', $output);
        self::assertSame($dotenv, file_get_contents($this->project->root . '/.env'));
        self::assertNull($this->users(Application::fromProjectRoot($this->project->root, []))->findByEmail('john@example.com'));
    }

    public function testChecksTheDatabaseBeforeWritingAnything(): void
    {
        [$exitCode, $output] = $this->install(['--admin-email' => 'jane@example.com', '--database-url' => 'pdo-mysql://nobody:wrong@127.0.0.1:1/frootbox']);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Could not connect to the database', $output);

        [$exitCode, $output] = $this->install(['--admin-email' => 'jane@example.com', '--database-url' => 'pgsql://localhost/frootbox']);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('"pgsql" is not supported', $output);

        self::assertFileDoesNotExist($this->project->root . '/.env');
    }

    public function testNeedsAnEmailWithoutQuestions(): void
    {
        [$exitCode, $output] = $this->install([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--admin-email', $output);
    }

    public function testRejectsAnInvalidEmail(): void
    {
        [$exitCode, $output] = $this->install(['--admin-email' => 'not-an-email']);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('email', $output);

        // Nothing is lost: the next attempt goes on.
        [$exitCode, $output] = $this->install(['--admin-email' => 'jane@example.com']);
        self::assertSame(0, $exitCode, $output);
    }

    public function testOtherCommandsPointToInstall(): void
    {
        $output = new BufferedOutput();
        $input = new ArrayInput(['command' => 'about']);

        self::assertSame(1, Application::console($this->project->root, $input, $output, []));
        self::assertStringContainsString('bin/frootbox install', $output->fetch());
    }

    public function testBuildsMysqlUrls(): void
    {
        self::assertSame(
            'pdo-mysql://web%40site:p%40ss%3Aw%2Ford%23@db.example.com:3307/my%20site?charset=utf8mb4',
            Installer::mysqlUrl('db.example.com', 3307, 'my site', 'web@site', 'p@ss:w/ord#'),
        );
        self::assertSame(20, \strlen(Installer::generatePassword()));
        self::assertNotSame(Installer::generatePassword(), Installer::generatePassword());
        self::assertSame([], new Installer(new ProjectPaths($this->project->root))->problems(Installer::DEFAULT_DATABASE_URL, writesDotenv: true));
    }

    /**
     * @param array<string, mixed>  $options
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function install(array $options, ?string $answers = null, array $env = []): array
    {
        $input = new ArrayInput(['command' => 'install', ...$options]);
        $input->setInteractive(null !== $answers);

        if (null !== $answers) {
            $stream = fopen('php://memory', 'r+');
            self::assertIsResource($stream);
            fwrite($stream, $answers);
            rewind($stream);
            $input->setStream($stream);
        }

        $output = new BufferedOutput();

        return [Application::console($this->project->root, $input, $output, $env), $output->fetch()];
    }

    private function assertSignsIn(string $email, string $password): void
    {
        $runner = Application::fromProjectRoot($this->project->root, [])->container()->get(Runner::class);
        self::assertInstanceOf(Runner::class, $runner);

        $signedIn = $runner->run(new SignIn($email, $password), Context::guest());

        self::assertSame($email, $signedIn->user->email);
    }

    private function users(Application $application): Users
    {
        $users = $application->container()->get(Users::class);
        self::assertInstanceOf(Users::class, $users);

        return $users;
    }
}
