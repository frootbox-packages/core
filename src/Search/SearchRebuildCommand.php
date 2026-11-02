<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Builds the index of the global search anew (decision 0214), e.g. after
 * own SQL that no action recorded, or after an update.
 */
#[AsCommand(name: 'search:rebuild', description: 'Builds the index of the global search anew, of one source or of all')]
final class SearchRebuildCommand extends Command
{
    public function __construct(
        private readonly SearchIndexer $indexer,
        private readonly SearchSources $sources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::OPTIONAL, 'Only this source, e.g. "page"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getArgument('source');
        $source = \is_string($source) ? $source : null;

        if (null !== $source && !$this->sources->has($source)) {
            $io->error(\sprintf('There is no source "%s". Known: %s.', $source, implode(', ', array_keys($this->sources->all()))));

            return Command::FAILURE;
        }

        $counts = $this->indexer->rebuild($source);
        $io->table(['Source', 'Items'], array_map(static fn(string $name, int $count): array => [$name, (string) $count], array_keys($counts), $counts));
        $io->success('The search index is built anew.');

        return Command::SUCCESS;
    }
}
