<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Connects the website to the hub with a code from its account area
 * (decision 0284), e.g. in automatic deployment:
 *
 *     php bin/frootbox hub:connect K7Q2-M9XD
 */
#[AsCommand(name: 'hub:connect', description: 'Connects the website to the hub with a code from it')]
final class HubConnectCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'The one-time code from the account area of the hub')
            ->addOption('no-status', null, InputOption::VALUE_NONE, 'Do not let the hub read the status of the website');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = $input->getArgument('code');

        try {
            $details = $this->runner->run(new CompleteHub(\is_string($code) ? $code : '', status: true !== $input->getOption('no-status')), Context::system(Channel::Console));
        } catch (ActionException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $io->success(null === $details->organization
            ? \sprintf('Connected to %s.', $details->hubUrl)
            : \sprintf('Connected to %s for %s.', $details->hubUrl, $details->organization));

        return Command::SUCCESS;
    }
}
