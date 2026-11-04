<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copies originals and files from forms from the bucket of STORAGE_DSN
 * into storage/ (decision 0268): as a backup, or before going back to
 * the folder.
 */
#[AsCommand(name: 'storage:pull', description: 'Copies the files in the bucket of STORAGE_DSN into storage/')]
final class StoragePullCommand extends Command
{
    public function __construct(private readonly FileStores $stores)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be copied');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->stores->isRemote(StoreRole::Storage)) {
            $io->error('STORAGE_DSN is not set: there is no bucket to copy from.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = StorageSync::copy(
            $this->stores->storage(),
            $this->stores->local(StoreRole::Storage),
            $dryRun,
            static fn(FileInfo $file, bool $copied) => $copied && $io->isVerbose() ? $io->writeln('  ' . $file->path) : null,
        );

        $io->success(\sprintf(
            '%s %d file(s) (%s) to storage/, %d there already.',
            $dryRun ? 'Would copy' : 'Copied',
            $result['copied'],
            StorageCheckCommand::size($result['bytes']),
            $result['same'],
        ));

        return Command::SUCCESS;
    }
}
