<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\User\CreateUser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a user through the action user.create and asks for the password
 * without showing it, e.g. the first administrator:
 *
 *     php bin/frootbox user:create jane@example.com --name "Jane Doe" --role admin
 */
#[AsCommand(name: 'user:create', description: 'Creates a user and asks for the password')]
final class UserCreateCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address to sign in with')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name, by default the part before the @')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Role name, e.g. admin; repeat for more')
            ->addOption('no-password', null, InputOption::VALUE_NONE, 'Create the user without a password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $email = \is_string($email) ? $email : '';
        $name = $input->getOption('name');
        $name = \is_string($name) && '' !== $name ? $name : strstr($email, '@', true);
        $roles = $input->getOption('role');
        $roles = \is_array($roles) ? array_values(array_filter($roles, \is_string(...))) : [];
        $password = null;

        if (true !== $input->getOption('no-password')) {
            if (!$input->isInteractive()) {
                $io->error('The password is asked for interactively. Use --no-password to create the user without one.');

                return Command::INVALID;
            }

            $password = $io->askHidden('Password');

            if ($password !== $io->askHidden('Repeat the password')) {
                $io->error('The passwords do not match.');

                return Command::INVALID;
            }
        }

        try {
            $profile = $this->runner->run(new CreateUser($email, \is_string($name) && '' !== $name ? $name : $email, \is_string($password) ? $password : null, $roles));
        } catch (InvalidInputException $error) {
            $io->error('Invalid input');
            $io->listing(array_map(static fn(string $pointer, string $message): string => ltrim($pointer, '/') . ': ' . $message, array_keys($error->errors), $error->errors));

            return Command::INVALID;
        } catch (ActionException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Created user #%d %s%s.',
            $profile->user->id,
            $profile->user->email,
            [] === $profile->roles ? '' : ' with the role ' . implode(', ', array_column($profile->roles, 'role')),
        ));

        return Command::SUCCESS;
    }
}
