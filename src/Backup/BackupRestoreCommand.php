<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Puts a backup of SQLite back (decision 0183). Everything changed since
 * is gone, except in the backup of the state before, which this makes
 * first.
 */
#[AsCommand(name: 'backup:restore', description: 'Restores a backup of SQLite after a backup of the current state')]
final class BackupRestoreCommand extends Command
{
    public function __construct(private readonly Restorer $restorer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The backup, as backup:list shows it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $name = \is_string($name) ? basename($name) : '';

        if ($input->isInteractive() && true !== $io->askQuestion(new ConfirmationQuestion(\sprintf('Replace the database with %s? Changes since then are only kept in a backup of the current state.', $name), false))) {
            return Command::FAILURE;
        }

        try {
            $result = $this->restorer->restore($name);
        } catch (BackupException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        if ([] !== $result['migrated']) {
            $io->writeln(\sprintf('Ran %d migration(s) the backup lacked.', \count($result['migrated'])));
        }

        $io->success(\sprintf('Restored %s. The state before is in %s.', $name, $result['before']->name));

        return Command::SUCCESS;
    }
}
