<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search\Sources;

use Frootbox\Core\Navigation\Definitions;
use Frootbox\Core\Navigation\NavigationReader;
use Frootbox\Core\Navigation\Navigations;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Search\SearchSource;

/**
 * The navigations of the theme in every language, the draft first, with
 * the own labels of their entries (decision 0214).
 */
#[AsSearchSource('navigation', 'Navigations')]
final readonly class NavigationSource implements SearchSource
{
    public function __construct(
        private Definitions $definitions,
        private NavigationReader $reader,
    ) {}

    public function changed(string $table, array $row): array
    {
        $name = match ($table) {
            Navigations::TABLE => $row['name'] ?? null,
            Revisions::TABLE => NavigationReader::TYPE === ($row['type'] ?? null) ? $row['entity_id'] ?? null : null,
            default => null,
        };

        return \is_string($name) ? [$name] : [];
    }

    public function items(): iterable
    {
        foreach ($this->definitions->all() as $definition) {
            yield $definition->name;
        }
    }

    public function entries(array $items): iterable
    {
        foreach ($items as $name) {
            $definition = $this->definitions->get($name);

            if (null === $definition) {
                continue;
            }

            foreach ($this->reader->current($name) as $language => $entries) {
                yield new SearchEntry(
                    item: $name,
                    title: $definition->title,
                    text: implode("\n", self::labels($entries)),
                    permission: 'navigation.show',
                    language: $language,
                    label: true,
                );
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<string>
     */
    private static function labels(array $entries): array
    {
        $labels = [];

        foreach ($entries as $entry) {
            if (\is_string($entry['label'] ?? null) && '' !== $entry['label']) {
                $labels[] = $entry['label'];
            }

            if (\is_array($entry['children'] ?? null)) {
                /** @var list<array<string, mixed>> $children */
                $children = array_values(array_filter($entry['children'], \is_array(...)));
                array_push($labels, ...self::labels($children));
            }
        }

        return $labels;
    }
}
