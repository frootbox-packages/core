<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Frootbox\Core\Action\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'backup:create', description: 'Makes a backup of the database that stays until it is deleted')]
final class BackupCreateCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backup = $this->runner->run(new CreateBackup());
        new SymfonyStyle($input, $output)->success(\sprintf('Backup storage/backups/%s created (%s).', $backup->name, BackupListCommand::size($backup->size)));

        return Command::SUCCESS;
    }
}
