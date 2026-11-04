<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Thins out the backups by age (decision 0183). schedule:run does the
 * same after each run.
 */
#[AsCommand(name: 'backup:prune', description: 'Thins out old backups like Time Machine')]
final class BackupPruneCommand extends Command
{
    public function __construct(private readonly BackupManager $backups)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deleted = $this->backups->prune();

        if ($output->isVerbose()) {
            $io->listing($deleted);
        }

        $io->success(\sprintf('Removed %d backup(s), %d left.', \count($deleted), \count($this->backups->all())));

        return Command::SUCCESS;
    }
}
