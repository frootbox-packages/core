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
 * Copies originals and files from forms from storage/ into the bucket of
 * STORAGE_DSN (decision 0268), only what is missing or of another size.
 * The folder stays as it is; remove it yourself once all works.
 */
#[AsCommand(name: 'storage:push', description: 'Copies the files in storage/ into the bucket of STORAGE_DSN')]
final class StoragePushCommand extends Command
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
            $io->error('STORAGE_DSN is not set: storage/ is the store already.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = StorageSync::copy(
            $this->stores->local(StoreRole::Storage),
            $this->stores->storage(),
            $dryRun,
            static fn(FileInfo $file, bool $copied) => $copied && $io->isVerbose() ? $io->writeln('  ' . $file->path) : null,
        );

        $io->success(\sprintf(
            '%s %d file(s) (%s) to %s, %d there already.',
            $dryRun ? 'Would copy' : 'Copied',
            $result['copied'],
            StorageCheckCommand::size($result['bytes']),
            $this->stores->storage()->name(),
            $result['same'],
        ));

        if ($this->stores->isRemote(StoreRole::Public)) {
            $io->note('Sizes and copies of public media are generated again in the public bucket. public/media/ is not used any more, except public/media/fonts/.');
        }

        return Command::SUCCESS;
    }
}
