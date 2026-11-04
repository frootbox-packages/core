<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Backup\BackupException;
use Frootbox\Core\Backup\BackupManager;
use Frootbox\Core\Backup\BackupReason;
use Frootbox\Core\Database\Migrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs all pending migrations of the core and all extensions, after a
 * backup of the database (decision 0183). If that fails, nothing runs
 * unless --no-backup is given.
 *
 * The detailed Doctrine commands are available as migrations:*.
 */
#[AsCommand(name: 'migrate', description: 'Runs all pending migrations of the core and all extensions')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly BackupManager $backups,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-backup', null, InputOption::VALUE_NONE, 'Migrate without a backup of the database before');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->migrator->pending() > 0 && !(bool) $input->getOption('no-backup') && $this->backups->available()) {
            try {
                $backup = $this->backups->create(BackupReason::Migrate);
                $io->writeln(\sprintf('Backup storage/backups/%s', $backup->name));
            } catch (BackupException $error) {
                $io->error($error->getMessage() . ' Nothing was migrated. Use --no-backup to migrate anyway.');

                return Command::FAILURE;
            }
        }

        $versions = $this->migrator->migrate();

        if ([] === $versions) {
            $io->success('The database is up to date.');

            return Command::SUCCESS;
        }

        $this->logger->info('Ran {count} migration(s): {versions}', ['count' => \count($versions), 'versions' => implode(', ', $versions)]);
        $io->listing($versions);
        $io->success(\sprintf('Ran %d migration(s).', \count($versions)));

        return Command::SUCCESS;
    }
}
