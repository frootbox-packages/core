<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Revision;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Change;
use Frootbox\Core\Action\ChangeKind;
use Frootbox\Database\Values;

/**
 * What a new revision started from (decision 0330): a draft is a copy of
 * what was published then, so the history compares it with that state,
 * and actions:prune keeps only the paths it changed. Data of a revision
 * stays as it is once it is published.
 */
final readonly class Baseline
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * The row an inserted revision started from, with only its data, or
     * null when the change is none or nothing was published before.
     *
     * @return array{data: string}|null
     */
    public function of(Change $change, DateTimeImmutable $at): ?array
    {
        $row = $change->newValues ?? [];

        if (ChangeKind::Insert !== $change->kind || Revisions::TABLE !== $change->target || !\is_string($row['type'] ?? null) || !\is_string($row['entity_id'] ?? null)) {
            return null;
        }

        $data = $this->published($row['type'], $row['entity_id'], $change->rowId ?? '', $at);

        return null === $data ? null : ['data' => $data];
    }

    /**
     * The data of the revision of an entity published last before a time.
     */
    public function published(string $type, string $entityId, int|string $exceptId, DateTimeImmutable $at): ?string
    {
        $data = $this->connection->fetchOne(
            $this->connection->getDatabasePlatform()->modifyLimitQuery('SELECT data FROM ' . Revisions::TABLE . ' WHERE type = ? AND entity_id = ? AND published_at IS NOT NULL AND published_at <= ? AND id <> ? ORDER BY published_at DESC, id DESC', 1),
            [$type, $entityId, $at->format(Values::DATE_FORMAT), (int) $exceptId],
        );

        return \is_string($data) ? $data : null;
    }
}
