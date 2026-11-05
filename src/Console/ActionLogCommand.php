<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\EntryStatus;
use Frootbox\Core\Action\LogEntry;
use Frootbox\Core\Auth\ActorType;
use Frootbox\Core\User\UserKind;
use Frootbox\Core\User\Users;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the latest entries of the action log. A service account is marked
 * as such (decision 0285).
 */
#[AsCommand(name: 'actions:log', description: 'Shows the latest actions: who, what, when, and whether they can be undone')]
final class ActionLogCommand extends Command
{
    public function __construct(private readonly ActionLog $log, private readonly Users $users)
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
        $entries = array_reverse($this->log->recent($limit));
        $ids = array_values(array_unique(array_filter(array_map(static fn(LogEntry $entry): ?int => ActorType::User->value === $entry->actorType ? $entry->actorId : null, $entries), is_int(...))));
        $services = array_values(array_filter($ids, fn(int $id): bool => UserKind::Service === $this->users->fetchById($id)?->kind));
        $rows = array_map(static fn(LogEntry $entry): array => self::row($entry, \in_array($entry->actorId, $services, true)), $entries);

        new SymfonyStyle($input, $output)->table(['ID', 'Time', 'Action', 'Actor', 'Channel', 'Status', 'Undo'], $rows);

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private static function row(LogEntry $entry, bool $service): array
    {
        return [
            (null === $entry->parentId ? '' : '  ') . '#' . $entry->id,
            $entry->createdAt->format('Y-m-d H:i:s'),
            $entry->action,
            ($entry->actorName ?? $entry->actorType) . ($service ? ' (service account)' : ''),
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
