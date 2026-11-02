<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use Frootbox\Core\Action\Change;

/**
 * Keeps the index current (decision 0214): after an action only the items
 * its changes concern, or everything anew.
 */
final readonly class SearchIndexer
{
    public function __construct(
        private SearchSources $sources,
        private SearchIndex $index,
    ) {}

    /**
     * Writes the entries of the items these changes concern again.
     *
     * @param list<Change> $changes
     */
    public function changed(array $changes): void
    {
        if ([] === $changes) {
            return;
        }

        foreach (array_keys($this->sources->all()) as $name) {
            $source = $this->sources->get($name);
            $items = [];

            foreach ($changes as $change) {
                foreach ([$change->newValues, $change->oldValues] as $row) {
                    foreach (null === $row ? [] : $source->changed($change->target, $row) as $item) {
                        $items[$item] = true;
                    }
                }
            }

            $items = array_map(strval(...), array_keys($items));

            if ([] !== $items) {
                $this->index->replace($name, $items, $source->entries($items));
            }
        }
    }

    /**
     * Builds the index of one source or of all anew.
     *
     * @return array<string, int> Items by source
     */
    public function rebuild(?string $only = null): array
    {
        $counts = [];

        foreach (array_keys($this->sources->all()) as $name) {
            if (null !== $only && $only !== $name) {
                continue;
            }

            $source = $this->sources->get($name);
            $this->index->clear($name);
            $counts[$name] = 0;

            foreach (self::chunks($source->items(), 100) as $items) {
                $this->index->replace($name, $items, $source->entries($items));
                $counts[$name] += \count($items);
            }
        }

        return $counts;
    }

    /**
     * @param iterable<string> $items
     *
     * @return iterable<list<string>>
     */
    private static function chunks(iterable $items, int $size): iterable
    {
        $chunk = [];

        foreach ($items as $item) {
            $chunk[] = $item;

            if (\count($chunk) === $size) {
                yield $chunk;
                $chunk = [];
            }
        }

        if ([] !== $chunk) {
            yield $chunk;
        }
    }
}
