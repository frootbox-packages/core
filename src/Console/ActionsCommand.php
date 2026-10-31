<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Action\Catalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists actions and queries, or shows the input schema of one.
 */
#[AsCommand(name: 'actions', description: 'Lists all actions and queries, or shows the input schema of one')]
final class ActionsCommand extends Command
{
    public function __construct(private readonly Catalog $catalog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Show the input schema of this action or query');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        if (\is_string($name)) {
            $descriptor = $this->catalog->get($name);
            $io->title($descriptor->name);
            $io->text($descriptor->description);
            $io->newLine();
            $output->writeln(json_encode($descriptor->schema(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($this->catalog->all() as $descriptor) {
            $rows[] = [
                $descriptor->name,
                $descriptor->kind->value,
                match (true) {
                    $descriptor->public => 'public',
                    $descriptor->signedIn => 'signed in',
                    default => $descriptor->permission,
                },
                $descriptor->description,
            ];
        }

        $io->table(['Name', 'Kind', 'Permission', 'Description'], $rows);

        return Command::SUCCESS;
    }
}
