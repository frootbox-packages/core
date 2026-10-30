<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Database\EntityMapper;
use Frootbox\Database\Values;
use LogicException;
use Throwable;

/**
 * The tables action_log (every run of an action) and action_changes
 * (what it changed, to undo it).
 *
 * The log is bookkeeping, not data: it is written directly, not through
 * actions.
 */
final readonly class ActionLog
{
    public const string ENTRIES = 'action_log';

    public const string CHANGES = 'action_changes';

    public function __construct(
        private Connection $connection,
        private Journal $journal,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public function start(Descriptor $descriptor, Context $context, array $input, ?int $parentId): int
    {
        return $this->insert(self::ENTRIES, $this->entry($descriptor, $context, $input, $parentId) + [
            'status' => EntryStatus::Running,
        ]);
    }

    public function succeeded(int $id, int $durationMs, bool $undoable): void
    {
        $this->journal->trusted(fn(): int|string => $this->connection->update(
            $this->quote(self::ENTRIES),
            $this->quoteKeys(Values::row(['status' => EntryStatus::Succeeded, 'duration_ms' => $durationMs, 'undoable' => $undoable])),
            [$this->quote('id') => $id],
        ));
    }

    /**
     * Logs an action that was not run: no permission or invalid input.
     *
     * @param array<string, mixed> $input
     */
    public function rejected(Descriptor $descriptor, Context $context, array $input, ?int $parentId, EntryStatus $status, string $error): void
    {
        $this->insert(self::ENTRIES, $this->entry($descriptor, $context, $input, $parentId) + [
            'status' => $status,
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function failed(Descriptor $descriptor, Context $context, array $input, ?int $parentId, Throwable $error, int $durationMs): void
    {
        $this->insert(self::ENTRIES, $this->entry($descriptor, $context, $input, $parentId) + [
            'status' => EntryStatus::Failed,
            'error' => $error::class . ': ' . $error->getMessage(),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Records a change of the running action. It belongs to the outermost
     * action, so undoing that one also undoes the nested ones.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function recordChange(ChangeKind $kind, string $target, int|string|null $rowId, ?array $before, ?array $after): void
    {
        $entryId = $this->journal->rootEntryId() ?? throw new LogicException('Changes can only be recorded while an action runs.');

        $this->insert(self::CHANGES, [
            'entry_id' => $entryId,
            'kind' => $kind,
            'target' => $target,
            'row_id' => null === $rowId ? null : (string) $rowId,
            'old_values' => $before,
            'new_values' => $after,
        ]);
    }

    public function find(int $id): ?LogEntry
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE %s = ?', $this->quote(self::ENTRIES), $this->quote('id')),
            [$id],
        );

        return false === $row ? null : EntityMapper::hydrate(LogEntry::class, $row);
    }

    /**
     * @return list<Change> in the order they happened
     */
    public function changes(int $entryId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT * FROM %s WHERE %s = ? ORDER BY %s', $this->quote(self::CHANGES), $this->quote('entry_id'), $this->quote('id')),
            [$entryId],
        );

        return array_map(static fn(array $row): Change => EntityMapper::hydrate(Change::class, $row), $rows);
    }

    /**
     * @return list<LogEntry> newest first
     */
    public function recent(int $limit = 20): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->quote(self::ENTRIES))
            ->orderBy($this->quote('id'), 'DESC')
            ->setMaxResults($limit);

        return array_map(static fn(array $row): LogEntry => EntityMapper::hydrate(LogEntry::class, $row), $query->executeQuery()->fetchAllAssociative());
    }

    /**
     * Forgets old changes (the actions can no longer be undone) and old
     * log entries.
     *
     * @return array{changes: int, entries: int} number of deleted rows
     */
    public function prune(DateTimeImmutable $changesBefore, DateTimeImmutable $entriesBefore): array
    {
        $changesBefore = max($changesBefore, $entriesBefore);

        return $this->journal->trusted(function () use ($changesBefore, $entriesBefore): array {
            $entries = $this->quote(self::ENTRIES);
            $createdAt = $this->quote('created_at');

            $changes = $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s IN (SELECT %s FROM %s WHERE %s < ?)', $this->quote(self::CHANGES), $this->quote('entry_id'), $this->quote('id'), $entries, $createdAt),
                [$changesBefore->format(Values::DATE_FORMAT)],
            );
            $this->connection->executeStatement(
                \sprintf('UPDATE %s SET %s = 0 WHERE %s = 1 AND %s < ?', $entries, $this->quote('undoable'), $this->quote('undoable'), $createdAt),
                [$changesBefore->format(Values::DATE_FORMAT)],
            );
            $deleted = $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s < ?', $entries, $createdAt),
                [$entriesBefore->format(Values::DATE_FORMAT)],
            );

            return ['changes' => (int) $changes, 'entries' => (int) $deleted];
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function entry(Descriptor $descriptor, Context $context, array $input, ?int $parentId): array
    {
        return [
            'parent_id' => $parentId,
            'action' => $descriptor->name,
            'channel' => $context->channel,
            'actor_type' => $context->actor->type,
            'actor_id' => $context->actor->id,
            'actor_name' => $context->actor->name,
            'input' => $input,
            'created_at' => new DateTimeImmutable(),
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function insert(string $table, array $values): int
    {
        return $this->journal->trusted(function () use ($table, $values): int {
            $this->connection->insert($this->quote($table), $this->quoteKeys(Values::row($values)));

            return (int) $this->connection->lastInsertId();
        });
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteSingleIdentifier($identifier);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function quoteKeys(array $row): array
    {
        $quoted = [];

        foreach ($row as $column => $value) {
            $quoted[$this->quote($column)] = $value;
        }

        return $quoted;
    }
}
