<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Doctrine\DBAL\Connection;

/**
 * The published page tree with titles only, read in two queries, for
 * navigations, the breadcrumb and what applies to a page from above
 * (decision 0117).
 */
final class PageOutline
{
    /** @var array<string, array{parentId: string|null, position: string, locked: Reach, excludeFromSearchEngines: Reach, excludeFromSiteSearch: Reach, titles: array<string, string>}>|null */
    private ?array $pages = null;

    public function __construct(private readonly Connection $connection) {}

    /**
     * @return array{parentId: string|null, position: string, locked: Reach, excludeFromSearchEngines: Reach, excludeFromSiteSearch: Reach, titles: array<string, string>}|null
     */
    public function get(string $id): ?array
    {
        return $this->pages()[$id] ?? null;
    }

    /**
     * Ids of the subpages, ordered by position.
     *
     * @return list<string>
     */
    public function children(string $id): array
    {
        $children = array_filter($this->pages(), static fn(array $page): bool => $page['parentId'] === $id);
        uksort($children, static fn(string $a, string $b): int => [$children[$a]['position'], $a] <=> [$children[$b]['position'], $b]);

        return array_keys($children);
    }

    /**
     * Ids from the start page down to the page itself.
     *
     * @return list<string>
     */
    public function trail(string $id): array
    {
        $trail = [];

        for ($current = $id; null !== $current && isset($this->pages()[$current]) && !\in_array($current, $trail, true); $current = $this->pages()[$current]['parentId']) {
            $trail[] = $current;
        }

        return array_reverse($trail);
    }

    /**
     * The title in the first of the languages that has one.
     *
     * @param list<string> $languages
     */
    public function title(string $id, array $languages): ?string
    {
        $titles = $this->get($id)['titles'] ?? [];

        foreach ($languages as $language) {
            if (isset($titles[$language])) {
                return $titles[$language];
            }
        }

        return null;
    }

    /**
     * The page whose lock applies, the page itself or one above it; null
     * if the page is not locked.
     */
    public function lockedBy(string $id): ?string
    {
        return $this->source($id, 'locked');
    }

    public function excludedFromSearchEnginesBy(string $id): ?string
    {
        return $this->source($id, 'excludeFromSearchEngines');
    }

    public function excludedFromSiteSearchBy(string $id): ?string
    {
        return $this->source($id, 'excludeFromSiteSearch');
    }

    /**
     * Forgets what was read, e.g. after publishing.
     */
    public function clear(): void
    {
        $this->pages = null;
    }

    /**
     * @return array<string, array{parentId: string|null, position: string, locked: Reach, excludeFromSearchEngines: Reach, excludeFromSiteSearch: Reach, titles: array<string, string>}>
     */
    private function pages(): array
    {
        if (null !== $this->pages) {
            return $this->pages;
        }

        $quote = $this->connection->quoteSingleIdentifier(...);
        $pages = [];

        $columns = ['id', 'parent_id', 'position', 'locked', 'exclude_from_search_engines', 'exclude_from_site_search'];
        $sql = \sprintf('SELECT %s FROM %s', implode(', ', array_map($quote, $columns)), $quote(Pages::TABLE));
        $reach = static fn(mixed $value): Reach => \is_string($value) ? Reach::tryFrom($value) ?? Reach::None : Reach::None;

        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            if (\is_string($row['id']) && \is_string($row['position'])) {
                $pages[$row['id']] = [
                    'parentId' => \is_string($row['parent_id']) ? $row['parent_id'] : null,
                    'position' => $row['position'],
                    'locked' => $reach($row['locked']),
                    'excludeFromSearchEngines' => $reach($row['exclude_from_search_engines']),
                    'excludeFromSiteSearch' => $reach($row['exclude_from_site_search']),
                    'titles' => [],
                ];
            }
        }

        $sql = \sprintf('SELECT %s, %s, %s FROM %s', $quote('page_id'), $quote('language'), $quote('title'), $quote(PageTranslations::TABLE));

        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            if (\is_string($row['page_id']) && \is_string($row['language']) && \is_string($row['title']) && isset($pages[$row['page_id']])) {
                $pages[$row['page_id']]['titles'][$row['language']] = $row['title'];
            }
        }

        return $this->pages = $pages;
    }

    /**
     * @param 'locked'|'excludeFromSearchEngines'|'excludeFromSiteSearch' $setting
     */
    private function source(string $id, string $setting): ?string
    {
        $trail = [];

        foreach ($this->trail($id) as $current) {
            $trail[$current] = $this->pages()[$current][$setting];
        }

        return Reach::source($trail);
    }
}
