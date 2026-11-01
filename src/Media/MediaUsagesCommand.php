<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Builds the index of where media are used anew (decision 0146), through
 * the action system.media-usages.
 */
#[AsCommand(name: 'media:usages', description: 'Builds the index of where media are used anew')]
final class MediaUsagesCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changed = $this->runner->run(new RebuildMediaUsages(), Context::system());

        new SymfonyStyle($input, $output)->success(\sprintf('The index of where media are used is up to date, %d entry/entries changed.', $changed));

        return Command::SUCCESS;
    }
}
