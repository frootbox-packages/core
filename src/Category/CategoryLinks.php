<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Database\Repository;

/**
 * The categories of the published objects (decision 0233), written by
 * ObjectCategories.
 *
 * @extends Repository<CategoryLink>
 */
final class CategoryLinks extends Repository
{
    public const string TABLE = 'category_links';

    /**
     * The ids of the categories of published objects, by object.
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
            $result[$link->targetId][] = $link->categoryId;
        }

        return $result;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return CategoryLink::class;
    }
}
