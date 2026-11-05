<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Disconnects the website from the hub (decision 0284). In a copy of a
 * website, e.g. from "workbench build --with-content", the connection
 * belongs to the original:
 *
 *     php bin/frootbox hub:disconnect --local --forget-key
 */
#[AsCommand(name: 'hub:disconnect', description: 'Disconnects the website from the hub')]
final class HubDisconnectCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('local', null, InputOption::VALUE_NONE, 'Only here, without telling the hub, e.g. in a copy')
            ->addOption('forget-key', null, InputOption::VALUE_NONE, 'Also forget the key pair of the website');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $forget = true === $input->getOption('forget-key');

        try {
            $this->runner->run(new DisconnectHub(notify: true !== $input->getOption('local'), forgetKey: $forget), Context::system(Channel::Console));
        } catch (TableNotFoundException) {
            // Not migrated yet: nothing to forget.
        } catch (ActionException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $io->success($forget ? 'The website has no connection and no key for the hub.' : 'Disconnected from the hub.');

        return Command::SUCCESS;
    }
}
