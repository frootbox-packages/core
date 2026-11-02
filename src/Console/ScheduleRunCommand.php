<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Schedule\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publishes and takes offline what is due, for a cron job every minute:
 *
 *     * * * * * php /path/to/project/bin/frootbox schedule:run
 */
#[AsCommand(name: 'schedule:run', description: 'Publishes and takes offline what is due; run it every minute from cron')]
final class ScheduleRunCommand extends Command
{
    public function __construct(private readonly Scheduler $scheduler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->scheduler->run();
        $io = new SymfonyStyle($input, $output);

        foreach ($result->failed as $message) {
            $io->warning($message);
        }

        $io->success(\sprintf('Published %d page(s), took %d page(s) offline.', \count($result->published), \count($result->unpublished)));

        return [] === $result->failed ? Command::SUCCESS : Command::FAILURE;
    }
}
