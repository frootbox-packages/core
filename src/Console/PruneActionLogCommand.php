<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use DateTimeImmutable;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\PruneOnSchedule;
use Frootbox\Core\Config\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shortens old changes and deletes old log entries (config
 * actions.undo_days, default 30, and actions.log_days, default 365,
 * decision 0174).
 */
#[AsCommand(name: 'actions:prune', description: 'Shortens old undo data and deletes old log entries')]
final class PruneActionLogCommand extends Command
{
    public function __construct(
        private readonly ActionLog $log,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pruned = PruneOnSchedule::prune($this->log, $this->config, new DateTimeImmutable());

        new SymfonyStyle($input, $output)->success(\sprintf(
            'Shortened %d changes older than %d days, they can no longer be undone, and deleted %d log entries older than %d days.',
            $pruned['shortened'],
            $pruned['undoDays'],
            $pruned['entries'],
            $pruned['logDays'],
        ));

        return Command::SUCCESS;
    }
}
