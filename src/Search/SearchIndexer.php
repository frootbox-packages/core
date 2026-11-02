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
 * its changes concern, or everything anew.
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

            if ($source instanceof Fingerprinted) {
                $this->store($name, $source->fingerprint());
            }
        }

        return $counts;
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
