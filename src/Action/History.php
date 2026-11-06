<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Frootbox\Database\EntityMapper;

/**
 * Everything the log knows about a set of rows (decision 0174): the
 * entries that changed them, newest first, with their changes, whole or
 * shortened. Pages use it for page.history, modules for their objects.
 *
 * Rows that are gone have no id to ask for. A deleted row still holds its
 * last state, so it is found by a text in it, e.g. the id of its page,
 * and a check of that state; then all its changes count.
 */
final readonly class History
{
    public const int MAX = 200;

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @param array<string, list<int|string>> $rows    row ids by table
     * @param array<string, string>           $deleted by table a text that deleted rows of interest hold, e.g. an id
     * @param (callable(Change): bool)|null   $belongs whether a deleted row found by its text is one of interest
     * @param int|null                        $before  only entries older than this one, to page through
     */
    public function of(array $rows, array $deleted = [], ?callable $belongs = null, int $limit = 50, ?int $before = null): HistoryPage
    {
        foreach ($deleted as $table => $text) {
            $rows[$table] = array_values(array_unique([...$rows[$table] ?? [], ...$this->deleted($table, $text, $belongs)]));
        }

        $limit = max(1, min(self::MAX, $limit));
        $matches = [];
        $query = $this->connection->createQueryBuilder()
            ->select('DISTINCT ' . $this->quote('entry_id'))
            ->from($this->quote(ActionLog::CHANGES))
            ->orderBy($this->quote('entry_id'), 'DESC')
            ->setMaxResults($limit + 1);

        foreach ($rows as $table => $ids) {
            if ([] === $ids) {
                continue;
            }

            $index = \count($matches);
            $matches[] = \sprintf('(%s = :target%d AND %s IN (:rows%d))', $this->quote('target'), $index, $this->quote('row_id'), $index);
            $query->setParameter('target' . $index, $table);
            $query->setParameter('rows' . $index, array_map(strval(...), $ids), ArrayParameterType::STRING);
        }

        if ([] === $matches) {
            return new HistoryPage([], null);
        }

        $query->where('(' . implode(' OR ', $matches) . ')');

        if (null !== $before) {
            $query->andWhere($this->quote('entry_id') . ' < :before')->setParameter('before', $before);
        }

        $ids = array_values(array_map(intval(...), array_filter($query->executeQuery()->fetchFirstColumn(), is_numeric(...))));
        $next = \count($ids) > $limit ? $ids[$limit - 1] : null;
        $ids = \array_slice($ids, 0, $limit);

        if ([] === $ids) {
            return new HistoryPage([], null);
        }

        $wanted = [];

        foreach ($rows as $table => $rowIds) {
            foreach ($rowIds as $rowId) {
                $wanted[$table . ':' . $rowId] = true;
            }
        }

        $changes = [];

        // One entry may change other rows too, e.g. when it publishes several pages.
        foreach ($this->rows(ActionLog::CHANGES, 'entry_id', $ids) as $row) {
            $change = EntityMapper::hydrate(Change::class, $row);

            if (isset($wanted[$change->target . ':' . $change->rowId])) {
                $changes[$change->entryId][] = $change;
            }
        }

        $entries = [];

        foreach ($this->rows(ActionLog::ENTRIES, 'id', $ids) as $row) {
            $entry = EntityMapper::hydrate(LogEntry::class, $row);
            $entries[$entry->id] = new HistoryEntry($entry, $changes[$entry->id] ?? []);
        }

        krsort($entries);

        return new HistoryPage(array_values($entries), $next);
    }

    /**
     * Ids of deleted rows that hold the text and belong.
     *
     * @param (callable(Change): bool)|null $belongs
     *
     * @return list<string>
     */
    private function deleted(string $table, string $text, ?callable $belongs): array
    {
        $found = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->quote(ActionLog::CHANGES))
            ->where($this->quote('kind') . ' = :kind')
            ->andWhere($this->quote('target') . ' = :target')
            ->andWhere($this->quote('old_values') . ' LIKE :text')
            ->setParameter('kind', ChangeKind::Delete->value)
            ->setParameter('target', $table)
            ->setParameter('text', '%' . addcslashes($text, '%_\\') . '%')
            ->executeQuery()
            ->fetchAllAssociative();
        $ids = [];

        foreach ($found as $row) {
            $change = EntityMapper::hydrate(Change::class, $row);

            if (null !== $change->rowId && (null === $belongs || $belongs($change))) {
                $ids[] = $change->rowId;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $column, array $ids): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->quote($table))
            ->where($this->quote($column) . ' IN (:ids)')
            ->orderBy($this->quote('id'))
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteSingleIdentifier($identifier);
    }
}
