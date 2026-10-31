<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Revision;

use Frootbox\Database\Repository;

/**
 * Drafts and versions of all entities (decisions 0058, 0094). Changing
 * them goes through the RevisionStore.
 *
 * @extends Repository<Revision>
 */
final class Revisions extends Repository
{
    public const string TABLE = 'revisions';

    public function draft(string $type, string $entityId): ?Revision
    {
        return $this->fetchOne(['where' => ['type' => $type, 'entityId' => $entityId, 'status' => RevisionStatus::Draft]]);
    }

    public function published(string $type, string $entityId): ?Revision
    {
        return $this->fetchOne(['where' => ['type' => $type, 'entityId' => $entityId, 'status' => RevisionStatus::Published]]);
    }

    public function version(string $type, string $entityId, int $version): ?Revision
    {
        return $this->fetchOne(['where' => ['type' => $type, 'entityId' => $entityId, 'version' => $version]]);
    }

    /**
     * Published and archived versions, the newest first.
     *
     * @return list<Revision>
     */
    public function versions(string $type, string $entityId): array
    {
        return $this->fetch([
            'where' => ['type' => $type, 'entityId' => $entityId, 'status' => [RevisionStatus::Published, RevisionStatus::Archived]],
            'order' => ['version desc'],
        ])->all();
    }

    /**
     * All drafts of a type by entity id.
     *
     * @return array<string, Revision>
     */
    public function drafts(string $type): array
    {
        $drafts = [];

        foreach ($this->fetch(['where' => ['type' => $type, 'status' => RevisionStatus::Draft]]) as $draft) {
            $drafts[$draft->entityId] = $draft;
        }

        return $drafts;
    }

    /**
     * The highest version so far, 0 if never published.
     */
    public function lastVersion(string $type, string $entityId): int
    {
        $quote = $this->connection->quoteSingleIdentifier(...);
        $version = $this->connection->fetchOne(
            \sprintf('SELECT MAX(%s) FROM %s WHERE %s = ? AND %s = ?', $quote('version'), $quote(self::TABLE), $quote('type'), $quote('entity_id')),
            [$type, $entityId],
        );

        return is_numeric($version) ? (int) $version : 0;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Revision::class;
    }
}
