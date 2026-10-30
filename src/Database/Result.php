<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * The entities a repository found.
 *
 * @template T of Entity
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class Result implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<T> $items
     */
    public function __construct(private array $items) {}

    /**
     * @return ArrayIterator<int, T>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    /**
     * @return T|null
     */
    public function first(): ?Entity
    {
        return $this->items[0] ?? null;
    }

    /**
     * @return list<T>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @template R
     *
     * @param callable(T): R $callback
     *
     * @return list<R>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->items);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return array_map(static fn(Entity $entity): array => $entity->toArray(), $this->items);
    }
}
