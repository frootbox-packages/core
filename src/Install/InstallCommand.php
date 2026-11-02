<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Install;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Application;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\Frootbox;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\Users;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UnexpectedValueException;

/**
 * Sets up an installation: .env, tables and the first superuser.
 *
 *     php bin/frootbox install
 *     php bin/frootbox install --no-interaction --admin-email jane@example.com
 *
 * Works before .env exists. Email, name and password of the superuser can
 * also come from FROOTBOX_ADMIN_EMAIL, FROOTBOX_ADMIN_NAME and
 * FROOTBOX_ADMIN_PASSWORD; the options win over the variables. Without
 * questions and without the variable, the password is generated and shown once.
 */
#[AsCommand(name: 'install', description: 'Sets up this installation: configuration, database and the first superuser')]
final class InstallCommand extends Command
{
    public const string EMAIL_VARIABLE = 'FROOTBOX_ADMIN_EMAIL';

    public const string NAME_VARIABLE = 'FROOTBOX_ADMIN_NAME';

    public const string PASSWORD_VARIABLE = 'FROOTBOX_ADMIN_PASSWORD';

    /**
     * @param array<string, string>|null $serverEnvironment Defaults to the real environment (getenv()).
     */
    public function __construct(
        private readonly ProjectPaths $paths,
        private readonly ?array $serverEnvironment = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'prod for live operation, dev for development', Environment::Production->value)
            ->addOption('database-url', null, InputOption::VALUE_REQUIRED, 'e.g. pdo-mysql://user:secret@localhost:3306/frootbox, by default SQLite in storage/')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Email of the first superuser, by default from ' . self::EMAIL_VARIABLE)
            ->addOption('admin-name', null, InputOption::VALUE_REQUIRED, 'Name of the first superuser, by default from ' . self::NAME_VARIABLE . ' or the part before the @');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = $this->serverEnvironment ?? getenv();
        $installer = new Installer($this->paths);
        $io->title('Frootbox ' . Frootbox::VERSION);

        try {
            if (!$installer->isConfigured($env)) {
                $this->writeConfiguration($input, $io, $installer);
            } elseif ([] !== $problems = $installer->problems(null, writesDotenv: false)) {
                throw new InstallException(implode("\n", $problems));
            }

            $container = Application::fromProjectRoot($this->paths->root, $env)->container();
            $this->service($container, CurrentContext::class)->set(Context::system(Channel::Console));

            if ($this->isInstalled($container)) {
                $io->error('Frootbox is already installed here. Create more users with bin/frootbox user:create.');

                return Command::FAILURE;
            }

            [$email, $name, $password, $generated] = $this->askAdmin($input, $io, $env);

            $migrations = $this->service($container, Migrator::class)->migrate();
            $io->text(\sprintf('Ran %d migrations.', \count($migrations)));

            $user = $this->service($container, Runner::class)->run(new CreateUser($email, $name, $password, ['superuser']))->user;
        } catch (InvalidInputException $error) {
            $io->error('Invalid input');
            $io->listing(array_map(static fn(string $pointer, string $message): string => ltrim($pointer, '/') . ': ' . $message, array_keys($error->errors), $error->errors));

            return Command::INVALID;
        } catch (InstallException|ActionException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Frootbox is installed. Superuser: %s', $user->email));

        if ($generated) {
            $io->warning(['Password: ' . $password, 'It is shown only this once. Change it after signing in.']);
        }

        $io->text([
            'Next steps:',
            '  Point the web server to ' . $this->paths->public(),
            '  Create an API token: php bin/frootbox user:token ' . $user->email,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Asks for environment and database, tests it and writes .env.
     */
    private function writeConfiguration(InputInterface $input, SymfonyStyle $io, Installer $installer): void
    {
        $environment = $this->environment($input, $io);
        $url = $this->databaseUrl($input, $io);

        if ([] !== $problems = $installer->problems($url, writesDotenv: true)) {
            throw new InstallException(implode("\n", $problems));
        }

        if (null !== $error = $installer->testConnection($url)) {
            throw new InstallException('Could not connect to the database: ' . $error);
        }

        $installer->writeDotenv($environment, $url);
        $io->text('Wrote .env');
    }

    private function environment(InputInterface $input, SymfonyStyle $io): Environment
    {
        $value = $input->getOption('env');
        $value = \is_string($value) ? $value : Environment::Production->value;

        if ($input->isInteractive() && !$input->hasParameterOption('--env')) {
            $value = $io->choice('Environment', ['prod' => 'live operation, everything cached', 'dev' => 'development, no caches'], $value);
        }

        return Environment::tryFrom(\is_string($value) ? $value : '')
            ?? throw new InstallException('--env must be "prod" or "dev".');
    }

    private function databaseUrl(InputInterface $input, SymfonyStyle $io): string
    {
        $url = $input->getOption('database-url');

        if (\is_string($url) && '' !== $url) {
            return $url;
        }

        if (!$input->isInteractive() || 'sqlite' === $io->choice('Database', ['sqlite' => 'SQLite, no setup needed', 'mysql' => 'MySQL or MariaDB'], 'sqlite')) {
            return Installer::DEFAULT_DATABASE_URL;
        }

        return Installer::mysqlUrl(
            self::text($io->ask('Host', 'localhost')),
            (int) self::text($io->ask('Port', '3306')),
            self::text($io->ask('Database name', 'frootbox')),
            self::text($io->ask('User')),
            self::secret($io->askHidden('Password (empty for none)', static fn(mixed $value): mixed => $value)),
        );
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{string, string, string, bool} email, name, password, whether the password was generated
     */
    private function askAdmin(InputInterface $input, SymfonyStyle $io, array $env): array
    {
        $email = self::text($input->getOption('admin-email'));
        $email = '' === $email ? self::text($env[self::EMAIL_VARIABLE] ?? '') : $email;
        $name = self::text($input->getOption('admin-name'));
        $name = '' === $name ? self::text($env[self::NAME_VARIABLE] ?? '') : $name;

        if ('' === $email) {
            $email = $input->isInteractive()
                ? self::text($io->ask('Email of the superuser'))
                : throw new InstallException(\sprintf('Set --admin-email or %s for the first superuser.', self::EMAIL_VARIABLE));
        }

        $default = (string) strstr($email, '@', true);

        if ('' === $name) {
            $name = $input->isInteractive() ? self::text($io->ask('Name', '' === $default ? null : $default)) : $default;
        }

        $name = '' === $name ? $email : $name;
        $password = $env[self::PASSWORD_VARIABLE] ?? '';

        if ('' !== $password) {
            return [$email, $name, $password, false];
        }

        if (!$input->isInteractive()) {
            return [$email, $name, Installer::generatePassword(), true];
        }

        while (true) {
            $password = self::secret($io->askHidden('Password (at least 10 characters)'));

            if ($password === self::secret($io->askHidden('Repeat the password'))) {
                return [$email, $name, $password, false];
            }

            $io->error('The passwords do not match.');
        }
    }

    private function isInstalled(ContainerInterface $container): bool
    {
        $connection = $this->service($container, Connection::class);

        return $connection->createSchemaManager()->tablesExist([Users::TABLE])
            && $this->service($container, Users::class)->count() > 0;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(ContainerInterface $container, string $class): object
    {
        $service = $container->get($class);

        return $service instanceof $class ? $service : throw new UnexpectedValueException(\sprintf('%s is not available.', $class));
    }

    private static function text(mixed $value): string
    {
        return trim(self::secret($value));
    }

    /**
     * Passwords keep their spaces.
     */
    private static function secret(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
