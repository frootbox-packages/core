<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Database\Repository;

/**
 * The stock of tags (decision 0233).
 *
 * @extends Repository<Tag>
 */
final class Tags extends Repository
{
    public const string TABLE = 'tags';

    /**
     * All tags that are not merged into another one, by key.
     *
     * @return list<Tag>
     */
    public function current(): array
    {
        return $this->fetch(['where' => ['mergedInto' => null], 'order' => 'key'])->all();
    }

    public function byKey(string $key): ?Tag
    {
        return $this->fetchOne(['where' => ['key' => $key]]);
    }

    /**
     * The tag a text names, by its key or any of its labels, without
     * regard to case; a merged tag leads to the one it went into.
     */
    public function named(string $text): ?Tag
    {
        foreach ($this->current() as $tag) {
            if ($tag->isNamed($text)) {
                return $tag;
            }
        }

        foreach ($this->fetch(['where' => ['mergedInto !=' => null]]) as $merged) {
            if ($merged->isNamed($text)) {
                return $this->resolve($merged->id);
            }
        }

        return null;
    }

    /**
     * The tag with this id, or the one it was merged into.
     */
    public function resolve(string $id): ?Tag
    {
        $tag = $this->fetchById($id);

        for ($steps = 0; null !== $tag && null !== $tag->mergedInto && $steps < 10; ++$steps) {
            $tag = $this->fetchById($tag->mergedInto);
        }

        return null === $tag || null !== $tag->mergedInto ? null : $tag;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Tag::class;
    }
}
