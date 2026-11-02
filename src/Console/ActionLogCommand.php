<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\EntryStatus;
use Frootbox\Core\Action\LogEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the latest entries of the action log.
 */
#[AsCommand(name: 'actions:log', description: 'Shows the latest actions: who, what, when, and whether they can be undone')]
final class ActionLogCommand extends Command
{
    public function __construct(private readonly ActionLog $log)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Number of entries', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        $limit = is_numeric($limit) ? max(1, (int) $limit) : 20;
        $rows = array_map(self::row(...), array_reverse($this->log->recent($limit)));

        new SymfonyStyle($input, $output)->table(['ID', 'Time', 'Action', 'Actor', 'Channel', 'Status', 'Undo'], $rows);

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private static function row(LogEntry $entry): array
    {
        return [
            (null === $entry->parentId ? '' : '  ') . '#' . $entry->id,
            $entry->createdAt->format('Y-m-d H:i:s'),
            $entry->action,
            $entry->actorName ?? $entry->actorType,
            $entry->channel,
            $entry->status->value . (null === $entry->error || EntryStatus::Succeeded === $entry->status ? '' : ': ' . mb_strimwidth($entry->error, 0, 60, '…')),
            match (true) {
                null !== $entry->undoneBy => 'undone by #' . $entry->undoneBy,
                $entry->undoable => 'yes',
                default => '',
            },
        ];
    }
}
