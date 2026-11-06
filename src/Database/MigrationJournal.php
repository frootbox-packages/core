<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Journal;
use Throwable;

/**
 * Leaves a trace of every migration in the log of actions (decision 0174):
 * an entry system.migrate with package, version and description, by the
 * system, and the rows it rewrote with updateRow() as changes, so they
 * show in the history of a page. Run by the action system.migrate, the
 * entries belong to its entry; otherwise they come from the console.
 *
 * Before the log exists, e.g. while the first migrations of an
 * installation run, nothing is written.
 */
final class MigrationJournal
{
    private bool $ready = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly ActionLog $log,
        private readonly Journal $journal,
        private readonly CurrentContext $context,
    ) {}

    /**
     * @param list<array{table: string, id: int|string, before: array<string, mixed>, after: array<string, mixed>}> $changes
     */
    public function migrated(PendingMigration $migration, array $changes, int $durationMs): void
    {
        if ($this->ready()) {
            $this->log->system(ActionLog::MIGRATE, $this->channel(), $this->input($migration), $this->journal->currentEntryId(), $durationMs, $changes);
        }
    }

    public function failed(PendingMigration $migration, Throwable $error, int $durationMs): void
    {
        if ($this->ready()) {
            $this->log->system(ActionLog::MIGRATE, $this->channel(), $this->input($migration), $this->journal->currentEntryId(), $durationMs, error: $error);
        }
    }

    private function ready(): bool
    {
        return $this->ready = $this->ready || $this->connection->createSchemaManager()->tablesExist([ActionLog::ENTRIES, ActionLog::CHANGES]);
    }

    private function channel(): Channel
    {
        return null === $this->journal->currentEntryId() ? Channel::Console : $this->context->get()->channel;
    }

    /**
     * @return array<string, mixed>
     */
    private function input(PendingMigration $migration): array
    {
        return ['package' => $migration->package, 'version' => $migration->version, 'description' => $migration->description];
    }
}
