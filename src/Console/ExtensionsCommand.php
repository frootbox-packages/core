<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\ProjectPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the installed extensions in load order.
 */
#[AsCommand(name: 'extensions', description: 'Lists the installed extensions in load order')]
final class ExtensionsCommand extends Command
{
    public function __construct(
        private readonly Extensions $extensions,
        private readonly ProjectPaths $paths,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (0 === \count($this->extensions)) {
            $io->info('No extensions installed. Create one with: php bin/frootbox make:extension acme/website');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($this->extensions as $package) {
            $rows[] = [
                $package->name,
                $package->title,
                $package->version ?? '',
                $package->isProject ? 'project' : 'package',
                $this->relative($package->path),
            ];
        }

        $io->table(['Name', 'Title', 'Version', 'Kind', 'Path'], $rows);

        return Command::SUCCESS;
    }

    private function relative(string $path): string
    {
        $root = realpath($this->paths->root);

        return false !== $root && str_starts_with($path, $root . '/') ? substr($path, \strlen($root) + 1) : $path;
    }
}
