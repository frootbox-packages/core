<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Cache\CacheFolder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Empties var/cache. Everything there is rebuilt on the next request. The
 * backend does the same with the action system.cache-clear.
 */
#[AsCommand(name: 'cache:clear', description: 'Empties var/cache, e.g. after deploying changed templates')]
final class CacheClearCommand extends Command
{
    public function __construct(private readonly CacheFolder $folder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        new SymfonyStyle($input, $output)->success(\sprintf('Removed %d cached file(s).', $this->folder->clear()));

        return Command::SUCCESS;
    }
}
