<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\ChangeKind;
use Frootbox\Core\Action\Journal;
use Frootbox\Database\DirectWriter;
use Frootbox\Database\EntityNotFoundException;
use Frootbox\Database\Rows;
use Frootbox\Database\Writer;
use LogicException;

/**
 * The writer of the CMS: writes only inside an action and records every
 * change with the state before and after, so the action can be undone.
 * Sensitive columns are recorded as "***" (see SensitiveColumns).
 */
final readonly class RecordingWriter implements Writer
{
    private DirectWriter $direct;

    public function __construct(
        Connection $connection,
        private Journal $journal,
        private ActionLog $log,
        private SensitiveColumns $sensitive,
    ) {
        $this->direct = new DirectWriter($connection);
    }

    public function insert(string $table, array $values): array
    {
        $this->requireAction($table);
        $after = $this->journal->trusted(fn(): array => $this->direct->insert($table, $values));
        $id = $after['id'] ?? null;
        $this->log->recordChange(ChangeKind::Insert, $table, \is_int($id) || \is_string($id) ? $id : null, null, $this->sensitive->mask($table, $after));

        return $after;
    }

    public function update(string $table, int|string $id, array $values): array
    {
        $this->requireAction($table);
        $before = $this->find($table, $id) ?? throw EntityNotFoundException::for($table, $id);
        $after = $this->journal->trusted(fn(): array => $this->direct->update($table, $id, $values));

        if ($this->sensitive->changed($table, $before, $after)) {
            $this->journal->preventUndo();
        }

        if (!Rows::same($before, $after)) {
            $this->log->recordChange(ChangeKind::Update, $table, $id, $this->sensitive->mask($table, $before), $this->sensitive->mask($table, $after));
        }

        return $after;
    }

    public function delete(string $table, int|string $id): bool
    {
        $this->requireAction($table);
        $before = $this->find($table, $id);

        if (null === $before) {
            return false;
        }

        $this->journal->trusted(fn(): bool => $this->direct->delete($table, $id));
        $this->log->recordChange(ChangeKind::Delete, $table, $id, $this->sensitive->mask($table, $before), null);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @phpstan-impure
     */
    public function find(string $table, int|string $id): ?array
    {
        return $this->direct->find($table, $id);
    }

    private function requireAction(string $table): void
    {
        if (!$this->journal->isRecording()) {
            throw new LogicException(\sprintf('Data in %s can only be changed inside an action. Write an action and run it with the Runner.', $table));
        }
    }
}
