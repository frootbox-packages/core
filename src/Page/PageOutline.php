<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Doctrine\DBAL\Connection;

/**
 * The published page tree with titles only, read in two queries, for
 * navigations and the breadcrumb.
 */
final class PageOutline
{
    /** @var array<string, array{parentId: string|null, position: string, titles: array<string, string>}>|null */
    private ?array $pages = null;

    public function __construct(private readonly Connection $connection) {}

    /**
     * @return array{parentId: string|null, position: string, titles: array<string, string>}|null
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
     * Forgets what was read, e.g. after publishing.
     */
    public function clear(): void
    {
        $this->pages = null;
    }

    /**
     * @return array<string, array{parentId: string|null, position: string, titles: array<string, string>}>
     */
    private function pages(): array
    {
        if (null !== $this->pages) {
            return $this->pages;
        }

        $quote = $this->connection->quoteSingleIdentifier(...);
        $pages = [];

        foreach ($this->connection->fetchAllAssociative(\sprintf('SELECT %s, %s, %s FROM %s', $quote('id'), $quote('parent_id'), $quote('position'), $quote(Pages::TABLE))) as $row) {
            if (\is_string($row['id']) && \is_string($row['position'])) {
                $pages[$row['id']] = ['parentId' => \is_string($row['parent_id']) ? $row['parent_id'] : null, 'position' => $row['position'], 'titles' => []];
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
}
