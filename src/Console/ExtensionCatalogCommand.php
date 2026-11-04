<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Extension\Check\CatalogWriter;
use Frootbox\Core\Extension\Check\CheckFinding;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the catalog for the directory from package folders (decision
 * 0282): catalog.json and the files of each package that passes
 * extension:check. Packages with errors are left out.
 */
#[AsCommand(name: 'extension:catalog', description: 'Writes catalog.json for the extension directory from package folders')]
final class ExtensionCatalogCommand extends Command
{
    public function __construct(private readonly CatalogWriter $writer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('folders', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Package folders, each with composer.json and frootbox.yaml')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'The folder for catalog.json and files/')
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'One version for all packages, e.g. "0.1.0"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $out = $input->getOption('out');

        if (!\is_string($out) || '' === $out) {
            $io->error('Name the folder for the catalog with --out.');

            return Command::FAILURE;
        }

        /** @var list<string> $folders */
        $folders = $input->getArgument('folders');
        $release = $input->getOption('release');
        $result = $this->writer->write($folders, $out, \is_string($release) && '' !== $release ? $release : null);

        foreach ($result['skipped'] as $name => $errors) {
            $io->warning(\sprintf('%s is left out, extension:check found %d problem(s):', $name, \count($errors)));
            $io->listing(array_map(static fn(CheckFinding $finding): string => (string) $finding, $errors));
        }

        $io->success(\sprintf('Wrote %s with %d package(s).', rtrim($out, '/') . '/' . CatalogWriter::FILE, \count($result['written'])));

        return [] === $result['skipped'] ? Command::SUCCESS : Command::FAILURE;
    }
}
