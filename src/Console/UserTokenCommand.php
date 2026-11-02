<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Account\CreateToken;
use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\User\Users;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates an API token for a user, e.g. for scripts or the REST API. It
 * runs account.create-token as that user, so the log shows them.
 *
 *     php bin/frootbox user:token jane@example.com --name "Deploy" --days 90
 */
#[AsCommand(name: 'user:token', description: 'Creates an API token for a user')]
final class UserTokenCommand extends Command
{
    public function __construct(
        private readonly Runner $runner,
        private readonly Users $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the user')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'What the token is for', 'Console')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Valid for this many days, without it until revoked');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $user = \is_string($email) ? $this->users->findByEmail($email) : null;
        $name = $input->getOption('name');
        $days = $input->getOption('days');

        if (null === $user) {
            $io->error('There is no user with this email address.');

            return Command::FAILURE;
        }

        if (null !== $days && !(\is_string($days) && ctype_digit($days))) {
            $io->error('--days must be a whole number.');

            return Command::INVALID;
        }

        try {
            $issued = $this->runner->run(
                new CreateToken(\is_string($name) ? $name : 'Console', null === $days ? null : (int) $days),
                new Context(Actor::user($user->id, $user->name), Channel::Console),
            );
        } catch (ActionException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created API token #%d for %s. It is shown only this once:', $issued->accessToken->id, $user->email));
        $output->writeln($issued->token);

        return Command::SUCCESS;
    }
}
