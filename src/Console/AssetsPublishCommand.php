<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Asset\PublicAssets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publishes the assets/ folders of all packages to public/assets again
 * (decision 0103). Pages do this for missing folders on their own; run
 * it after a deployment so changed files are copied.
 */
#[AsCommand(name: 'assets:publish', description: 'Publishes the assets/ folders of all packages to public/assets')]
final class AssetsPublishCommand extends Command
{
    public function __construct(private readonly PublicAssets $assets)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->assets->publishAll();
        new SymfonyStyle($input, $output)->success(\sprintf('Published %d assets/ folder(s).', $count));

        return Command::SUCCESS;
    }
}
