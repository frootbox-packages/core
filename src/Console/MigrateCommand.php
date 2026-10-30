<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Database\Migrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs all pending migrations of the core and all extensions.
 *
 * The detailed Doctrine commands are available as migrations:*.
 */
#[AsCommand(name: 'migrate', description: 'Runs all pending migrations of the core and all extensions')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
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
