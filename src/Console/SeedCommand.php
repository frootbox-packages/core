<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Seed\Seeder;
use Frootbox\Core\Seed\SeedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the actions of a seed file, e.g. demo content:
 *
 *     php bin/frootbox seed frootbox/theme-bohnenglueck:demo/site.yaml
 */
#[AsCommand(name: 'seed', description: 'Runs the actions of a YAML file, e.g. to create demo content')]
final class SeedCommand extends Command
{
    public function __construct(private readonly Seeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path of the file, or vendor/package:path in an extension');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('file');

        try {
            $steps = $this->seeder->read($this->seeder->file(\is_string($path) ? $path : '', (string) getcwd()));
            $this->seeder->run($steps, static fn(int $number, string $name) => $io->writeln(\sprintf('<comment>%3d</comment> %s', $number, $name), OutputInterface::VERBOSITY_VERBOSE));
        } catch (SeedException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Ran %d step(s).', \count($steps)));

        return Command::SUCCESS;
    }
}
