<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Frootbox\Core\Action\Schema\InputMapper;

/**
 * Columns such as password hashes that must not end up in the recorded
 * changes. They are recorded as "***", so undoing cannot bring them back:
 *
 * - changing one makes the action not undoable,
 * - undoing a delete restores the row with the column empty.
 *
 * Extensions register them with $registry->sensitive('table', 'column').
 */
final class SensitiveColumns
{
    /** @var array<string, list<string>> */
    private array $columns = [];

    public function add(string $table, string ...$columns): void
    {
        $this->columns[$table] = array_values(array_unique([...$this->columns[$table] ?? [], ...$columns]));
    }

    /**
     * @return list<string>
     */
    public function of(string $table): array
    {
        return $this->columns[$table] ?? [];
    }

    /**
     * Replaces the values of sensitive columns with "***".
     *
     * @param array<string, mixed>|null $row
     *
     * @return ($row is null ? null : array<string, mixed>)
     */
    public function mask(string $table, ?array $row): ?array
    {
        if (null === $row) {
            return null;
        }

        foreach ($this->of($table) as $column) {
            if (isset($row[$column])) {
                $row[$column] = InputMapper::MASK;
            }
        }

        return $row;
    }

    /**
     * Removes sensitive columns, so writing the row back leaves them alone.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function without(string $table, array $row): array
    {
        return array_diff_key($row, array_flip($this->of($table)));
    }

    /**
     * Empties sensitive columns, for restoring a deleted row.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function cleared(string $table, array $row): array
    {
        foreach ($this->of($table) as $column) {
            if (\array_key_exists($column, $row)) {
                $row[$column] = null;
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function changed(string $table, array $before, array $after): bool
    {
        foreach ($this->of($table) as $column) {
            if (($before[$column] ?? null) !== ($after[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
