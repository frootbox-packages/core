<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Queue\Worker;
use Frootbox\Core\Schedule\CronStatus;
use Frootbox\Core\Schedule\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publishes and takes offline what is due, then runs the jobs of the
 * queue (decision 0221), for a cron job every minute:
 *
 *     * * * * * php /path/to/project/bin/frootbox schedule:run
 */
#[AsCommand(name: 'schedule:run', description: 'Publishes and takes offline what is due, then runs the queue; run it every minute from cron')]
final class ScheduleRunCommand extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Worker $worker,
        private readonly CronStatus $cron,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('queue-seconds', null, InputOption::VALUE_REQUIRED, 'Start no new job of the queue after this many seconds, 0 for none', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cron->touch();
        $result = $this->scheduler->run();
        $io = new SymfonyStyle($input, $output);
        $seconds = $input->getOption('queue-seconds');
        $seconds = is_numeric($seconds) ? (float) $seconds : 50.0;

        foreach ($result->failed as $message) {
            $io->warning($message);
        }

        $io->success(\sprintf('Published %d page(s), took %d page(s) offline.', \count($result->published), \count($result->unpublished)));

        if ($seconds > 0) {
            $jobs = $this->worker->run($seconds);

            if ($jobs->total() > 0) {
                $io->success(\sprintf('Ran %d job(s): %d done, %d to try again, %d failed.', $jobs->total(), $jobs->done, $jobs->retried, $jobs->failed));
            }
        }

        return [] === $result->failed ? Command::SUCCESS : Command::FAILURE;
    }
}
