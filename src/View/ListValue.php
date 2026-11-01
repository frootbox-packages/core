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

    /**
     * The entries with their places for the editing mode, e.g.
     * "slides/0/" (decision 0119).
     */
    public function within(EditMarkup $markup, string $path): self
    {
        return new self(array_map(static fn(int $index, Scope $entry): Scope => $entry->within($markup, $path . $index . '/'), array_keys($this->entries), $this->entries));
    }

    /**
     * Only the entries with a value, for visitors (decision 0144).
     */
    public function withoutBlank(): self
    {
        return new self(array_values(array_filter($this->entries, static fn(Scope $entry): bool => !$entry->isBlank())));
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
