<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use DateTimeImmutable;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Config\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Forgets old changes and log entries (config actions.undo_days, default
 * 30, and actions.log_days, default 365).
 */
#[AsCommand(name: 'actions:prune', description: 'Deletes old undo data and old log entries')]
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
        $undoDays = $this->config->int('actions.undo_days', 30);
        $logDays = $this->config->int('actions.log_days', 365);
        $now = new DateTimeImmutable();

        $deleted = $this->log->prune($now->modify(\sprintf('-%d days', $undoDays)), $now->modify(\sprintf('-%d days', $logDays)));

        new SymfonyStyle($input, $output)->success(\sprintf(
            'Deleted %d changes older than %d days and %d log entries older than %d days.',
            $deleted['changes'],
            $undoDays,
            $deleted['entries'],
            $logDays,
        ));

        return Command::SUCCESS;
    }
}
