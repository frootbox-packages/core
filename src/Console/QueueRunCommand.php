<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Queue\Worker;
use Frootbox\Core\Schedule\CronStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the jobs that are due (decision 0221). schedule:run does this
 * too, so one cron job is enough.
 */
#[AsCommand(name: 'queue:run', description: 'Runs the jobs of the queue that are due')]
final class QueueRunCommand extends Command
{
    public function __construct(
        private readonly Worker $worker,
        private readonly CronStatus $cron,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Start no new job after this many seconds', '50');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Run at most this many jobs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seconds = $input->getOption('seconds');
        $limit = $input->getOption('limit');

        if (!is_numeric($seconds) || (null !== $limit && (!\is_string($limit) || !ctype_digit($limit)))) {
            $io->error('--seconds must be a number and --limit a whole number.');

            return Command::INVALID;
        }

        $this->cron->touch();
        $result = $this->worker->run((float) $seconds, null === $limit ? null : (int) $limit);
        $io->success(\sprintf('Ran %d job(s): %d done, %d to try again, %d failed.', $result->total(), $result->done, $result->retried, $result->failed));

        return 0 === $result->failed ? Command::SUCCESS : Command::FAILURE;
    }
}
