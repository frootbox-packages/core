<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use FilesystemIterator;
use Frootbox\Core\ProjectPaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Empties var/cache. Everything there is rebuilt on the next request.
 */
#[AsCommand(name: 'cache:clear', description: 'Empties var/cache, e.g. after deploying changed templates')]
final class CacheClearCommand extends Command
{
    public function __construct(private readonly ProjectPaths $paths)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $this->paths->var('cache');
        $removed = 0;

        if (is_dir($directory)) {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($items as $item) {
                if (!$item instanceof SplFileInfo) {
                    continue;
                }

                if ($item->isDir() && !$item->isLink()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                    ++$removed;
                }
            }
        }

        new SymfonyStyle($input, $output)->success(\sprintf('Removed %d cached file(s).', $removed));

        return Command::SUCCESS;
    }
}
