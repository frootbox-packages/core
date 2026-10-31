<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Stringable;

/**
 * The entries of a list field: {% for slide in field('slides') %}.
 *
 * @implements IteratorAggregate<int, Scope>
 */
final readonly class ListValue implements IteratorAggregate, Countable, Stringable
{
    /**
     * @param list<Scope> $entries
     */
    public function __construct(private array $entries = []) {}

    /**
     * @return ArrayIterator<int, Scope>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->entries);
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * A list is looped over, not printed.
     */
    public function __toString(): string
    {
        return '';
    }
}
