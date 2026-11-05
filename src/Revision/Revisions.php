<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Revision;

use DateTimeImmutable;
use Frootbox\Database\Repository;
use Frootbox\Database\Values;

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
     * Drafts whose time to be published has come and published versions
     * whose time to be taken offline has come, by type.
     *
     * @return array{publish: array<string, list<string>>, unpublish: array<string, list<string>>} Entity ids by type
     */
    public function due(DateTimeImmutable $now): array
    {
        $quote = $this->connection->quoteSingleIdentifier(...);
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT %1$s, %2$s, %3$s FROM %4$s WHERE (%3$s = ? AND %5$s <= ?) OR (%3$s = ? AND %6$s <= ?) ORDER BY %7$s',
                $quote('type'),
                $quote('entity_id'),
                $quote('status'),
                $quote(self::TABLE),
                $quote('publish_at'),
                $quote('unpublish_at'),
                $quote('id'),
            ),
            [RevisionStatus::Draft->value, Values::toDatabase($now), RevisionStatus::Published->value, Values::toDatabase($now)],
        );
        $due = ['publish' => [], 'unpublish' => []];

        foreach ($rows as $row) {
            if (\is_string($row['type']) && \is_string($row['entity_id'])) {
                $due[RevisionStatus::Draft->value === $row['status'] ? 'publish' : 'unpublish'][$row['type']][] = $row['entity_id'];
            }
        }

        return $due;
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

    /**
     * When content was last saved or published, of any type (decision 0284).
     */
    public function lastChange(): ?DateTimeImmutable
    {
        $quote = $this->connection->quoteSingleIdentifier(...);
        $time = $this->connection->fetchOne(\sprintf(
            'SELECT MAX(COALESCE(%s, %s)) FROM %s',
            $quote('updated_at'),
            $quote('created_at'),
            $quote(self::TABLE),
        ));

        return \is_string($time) && '' !== $time ? new DateTimeImmutable($time) : null;
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
