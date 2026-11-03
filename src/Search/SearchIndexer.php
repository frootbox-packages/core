<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use Frootbox\Core\Action\Change;
use Frootbox\Core\ProjectPaths;

/**
 * Keeps the index current (decision 0214): after an action only the items
 * its changes concern, or everything anew. The published state for the
 * search on the website (decision 0228) comes from the sources that
 * describe it; the module of that search asks for it.
 */
final readonly class SearchIndexer
{
    /** Where the fingerprints of sources from files are kept, e.g. of the help. */
    private const string FINGERPRINTS = 'cache/search/%s.fingerprint';

    public function __construct(
        private SearchSources $sources,
        private SearchIndex $index,
        private ProjectPaths $paths,
    ) {}

    /**
     * Reads the sources from files again whose files changed, e.g. after
     * an update brought new help pages (decision 0218).
     */
    public function refresh(): void
    {
        foreach (array_keys($this->sources->all()) as $name) {
            $source = $this->sources->get($name);

            if ($source instanceof Fingerprinted && $this->stored($name) !== $source->fingerprint()) {
                $this->rebuild($name);
            }
        }
    }

    /**
     * Writes the entries of the items these changes concern again.
     *
     * @param list<Change> $changes
     * @param string       $state   SearchIndex::DRAFT or SearchIndex::LIVE
     */
    public function changed(array $changes, string $state = SearchIndex::DRAFT): void
    {
        if ([] === $changes) {
            return;
        }

        foreach (array_keys($this->sources->all()) as $name) {
            $source = $this->sources->get($name);

            if (SearchIndex::LIVE === $state && !$source instanceof PublishedSource) {
                continue;
            }

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
                $this->index->replace($name, $items, self::entries($source, $items, $state), $state);
            }
        }
    }

    /**
     * Builds the index of one source or of all anew.
     *
     * @param string $state SearchIndex::DRAFT or SearchIndex::LIVE
     *
     * @return array<string, int> Items by source
     */
    public function rebuild(?string $only = null, string $state = SearchIndex::DRAFT): array
    {
        $counts = [];

        foreach (array_keys($this->sources->all()) as $name) {
            $source = $this->sources->get($name);

            if ((null !== $only && $only !== $name) || (SearchIndex::LIVE === $state && !$source instanceof PublishedSource)) {
                continue;
            }

            $this->index->clear($name, $state);
            $counts[$name] = 0;

            foreach (self::chunks($source->items(), 100) as $items) {
                $this->index->replace($name, $items, self::entries($source, $items, $state), $state);
                $counts[$name] += \count($items);
            }

            if (SearchIndex::DRAFT === $state && $source instanceof Fingerprinted) {
                $this->store($name, $source->fingerprint());
            }
        }

        return $counts;
    }

    /**
     * @param list<string> $items
     *
     * @return iterable<PublishedEntry|SearchEntry>
     */
    private static function entries(SearchSource $source, array $items, string $state): iterable
    {
        if (SearchIndex::LIVE !== $state) {
            return $source->entries($items);
        }

        return $source instanceof PublishedSource ? $source->published($items) : [];
    }

    private function stored(string $source): ?string
    {
        $file = $this->file($source);

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    private function store(string $source, string $fingerprint): void
    {
        $file = $this->file($source);

        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o775, true);
        }

        file_put_contents($file, $fingerprint, \LOCK_EX);
    }

    private function file(string $source): string
    {
        return $this->paths->var(\sprintf(self::FINGERPRINTS, str_replace('/', '--', $source)));
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
