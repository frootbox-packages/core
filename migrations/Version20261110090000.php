<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Database\Migration;

/**
 * Headings in rich text store their rank instead of their level (decision
 * 0129): 2 becomes 1, 3 becomes 2, 4 and deeper become 3. Pages, drafts,
 * versions and what undo restores.
 */
final class Version20261110090000 extends Migration
{
    /** Columns with content, by table. */
    private const array COLUMNS = [
        'page_translations' => ['content'],
        'revisions' => ['data'],
        'action_changes' => ['old_values', 'new_values'],
    ];

    public function getDescription(): string
    {
        return 'Stores the rank of headings in rich text';
    }

    public function up(Schema $schema): void
    {
        $this->convert(static fn(int $level): int => max(1, min(3, $level - 1)));
    }

    public function down(Schema $schema): void
    {
        $this->convert(static fn(int $rank): int => min(6, $rank + 1));
    }

    /**
     * @param callable(int): int $change
     */
    private function convert(callable $change): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $rows = $this->connection->fetchAllAssociative(\sprintf('SELECT id, %1$s FROM %2$s WHERE %1$s LIKE ?', $column, $table), ['%"heading"%']);

                foreach ($rows as $row) {
                    $json = $row[$column] ?? null;

                    if (!\is_string($json)) {
                        continue;
                    }

                    $before = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
                    $after = self::walk($before, $change);

                    if ($after !== $before) {
                        $this->addSql(\sprintf('UPDATE %s SET %s = ? WHERE id = ?', $table, $column), [json_encode($after, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $row['id']]);
                    }
                }
            }
        }
    }

    /**
     * @param callable(int): int $change
     */
    private static function walk(mixed $value, callable $change): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if ('heading' === ($value['type'] ?? null) && \is_array($value['attrs'] ?? null) && \is_int($value['attrs']['level'] ?? null)) {
            $value['attrs']['level'] = $change($value['attrs']['level']);
        }

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::walk($item, $change);
            }
        }

        return $value;
    }
}
