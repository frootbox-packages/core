<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Database\Repository;

/**
 * The tags of the published objects (decision 0233), written by
 * ObjectTags.
 *
 * @extends Repository<TagLink>
 */
final class TagLinks extends Repository
{
    public const string TABLE = 'tag_links';

    /**
     * The ids of the tags of published objects, by object.
     *
     * @param list<string> $ids
     *
     * @return array<string, list<string>>
     */
    public function idsOf(string $type, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $result = [];

        foreach ($this->fetch(['where' => ['targetType' => $type, 'targetId' => $ids], 'order' => 'id']) as $link) {
            $result[$link->targetId][] = $link->tagId;
        }

        return $result;
    }

    /**
     * How many published objects have each tag, by id of the tag.
     *
     * @return array<string, int>
     */
    public function counts(?string $type = null): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('tag_id', 'COUNT(*) AS amount')
            ->from(self::TABLE)
            ->groupBy('tag_id');

        if (null !== $type) {
            $query->where('target_type = :type')->setParameter('type', $type);
        }

        $counts = [];

        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $id = $row['tag_id'] ?? null;
            $amount = $row['amount'] ?? null;

            if (\is_string($id) && is_numeric($amount)) {
                $counts[$id] = (int) $amount;
            }
        }

        return $counts;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return TagLink::class;
    }
}
