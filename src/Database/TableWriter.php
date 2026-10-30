<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\ChangeKind;
use Frootbox\Core\Action\Journal;
use LogicException;

/**
 * Writes single rows by their id and records each change with the state
 * before and after, so the running action can be undone.
 *
 * Writing is only possible inside an action.
 */
final readonly class TableWriter
{
    public function __construct(
        private Connection $connection,
        private Journal $journal,
        private ActionLog $log,
    ) {}

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> the new row
     */
    public function insert(string $table, array $values): array
    {
        $this->requireAction($table);
        $row = Values::row($values);

        $id = $this->journal->trusted(function () use ($table, $row): int|string {
            $this->connection->insert($this->quote($table), $this->quoteKeys($row));

            $id = $row['id'] ?? null;

            return \is_int($id) || \is_string($id) ? $id : $this->connection->lastInsertId();
        });

        $after = $this->find($table, $id) ?? throw EntityNotFoundException::for($table, $id);
        $this->log->recordChange(ChangeKind::Insert, $table, $id, null, $after);

        return $after;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> the changed row
     */
    public function update(string $table, int|string $id, array $values): array
    {
        $this->requireAction($table);
        $before = $this->find($table, $id) ?? throw EntityNotFoundException::for($table, $id);
        $row = Values::row($values);
        unset($row['id']);

        if ([] !== $row) {
            $this->journal->trusted(fn(): int|string => $this->connection->update($this->quote($table), $this->quoteKeys($row), [$this->quote('id') => $id]));
        }

        $after = $this->find($table, $id) ?? throw EntityNotFoundException::for($table, $id);

        if (!Rows::same($before, $after)) {
            $this->log->recordChange(ChangeKind::Update, $table, $id, $before, $after);
        }

        return $after;
    }

    /**
     * @return bool false if there was no such row
     */
    public function delete(string $table, int|string $id): bool
    {
        $this->requireAction($table);
        $before = $this->find($table, $id);

        if (null === $before) {
            return false;
        }

        $this->journal->trusted(fn(): int|string => $this->connection->delete($this->quote($table), [$this->quote('id') => $id]));
        $this->log->recordChange(ChangeKind::Delete, $table, $id, $before, null);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @phpstan-impure
     */
    public function find(string $table, int|string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE %s = ?', $this->quote($table), $this->quote('id')),
            [$id],
        );

        return false === $row ? null : $row;
    }

    private function requireAction(string $table): void
    {
        if (!$this->journal->isRecording()) {
            throw new LogicException(\sprintf('Data in %s can only be changed inside an action. Write an action and run it with the Runner.', $table));
        }
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
