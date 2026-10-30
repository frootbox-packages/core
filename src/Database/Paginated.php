<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * One page of entities plus the numbers for a pagination.
 *
 * @template T of Entity
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class Paginated implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param Result<T> $items
     */
    public function __construct(
        public Result $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->pages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return $this->items->getIterator();
    }

    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, pages: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->items->jsonSerialize(),
            'total' => $this->total,
            'page' => $this->page,
            'perPage' => $this->perPage,
            'pages' => $this->pages(),
        ];
    }
}
