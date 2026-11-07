<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ends all sessions of users and visitors (decision 0319), e.g. in a copy
 * of a website:
 *
 *     php bin/frootbox sessions:end
 */
#[AsCommand(name: 'sessions:end', description: 'Ends the sessions of all users and visitors; API tokens stay')]
final class SessionsEndCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $count = $this->runner->run(new EndSessions(), Context::system(Channel::Console));
        } catch (TableNotFoundException) {
            // Not migrated yet: no sessions.
            $count = 0;
        }

        new SymfonyStyle($input, $output)->success(\sprintf('Ended %d sessions.', $count));

        return Command::SUCCESS;
    }
}
