<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Reference;

use Frootbox\Core\Extension\Registry;
use Frootbox\Core\ProjectPaths;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the reference of the installed packages as Markdown, by default
 * into documentation/reference/ of the project.
 */
#[AsCommand(name: 'reference:build', description: 'Writes the reference for developers from the code of the installed packages')]
final class ReferenceCommand extends Command
{
    public function __construct(
        private readonly Registry $registry,
        private readonly ContainerInterface $container,
        private readonly ProjectPaths $paths,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'The folder, relative to the project', 'documentation/reference')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Write nothing, fail if the folder is behind');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $folder = $input->getOption('output');
        $folder = $this->paths->root . '/' . trim(\is_string($folder) ? $folder : 'documentation/reference', '/');
        $check = true === $input->getOption('check');
        $changed = Reference::sync($folder, new Reference($this->registry, $this->container)->pages(), $check);

        if ([] === $changed) {
            $io->success('The reference is up to date.');

            return Command::SUCCESS;
        }

        if ($check) {
            $io->error('The reference is behind: ' . implode(', ', $changed) . '. Run reference:build.');

            return Command::FAILURE;
        }

        $io->success('Wrote ' . implode(', ', $changed) . '.');

        return Command::SUCCESS;
    }
}
