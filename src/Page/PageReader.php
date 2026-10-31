<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Database\EntityNotFoundException;

/**
 * Reads pages with their drafts, the way editors see them.
 */
final readonly class PageReader
{
    public const string TYPE = 'page';

    public function __construct(
        private Pages $pages,
        private PageTranslations $translations,
        private Revisions $revisions,
    ) {}

    public function published(string $id): ?PageData
    {
        $page = $this->pages->fetchById($id);

        return null === $page ? null : PageData::fromPage($page, ...array_values($this->translations->of($id)));
    }

    public function draft(string $id): ?PageData
    {
        $draft = $this->revisions->draft(self::TYPE, $id);

        return null === $draft ? null : PageData::fromArray($draft->data);
    }

    /**
     * @throws EntityNotFoundException
     */
    public function details(string $id): PageDetails
    {
        $draft = $this->draft($id);
        $published = $this->published($id);

        if (null === $draft && null === $published) {
            throw EntityNotFoundException::for(Pages::TABLE, $id);
        }

        return new PageDetails(
            $id,
            self::status($draft, $published),
            $this->revisions->published(self::TYPE, $id)?->version,
            $draft,
            $published,
        );
    }

    /**
     * All pages, drafts over published state.
     *
     * @return array<string, PageNode> by id
     */
    public function nodes(): array
    {
        return $this->merge($this->publishedPages(), $this->drafts());
    }

    /**
     * The published pages as they would be after publishing the given
     * drafts.
     *
     * @param list<string> $ids
     *
     * @return array<string, PageNode> by id
     */
    public function nodesAfterPublishing(array $ids): array
    {
        return $this->merge($this->publishedPages(), array_intersect_key($this->drafts(), array_flip($ids)));
    }

    /**
     * Pages with this parent, ordered by position.
     *
     * @param array<string, PageNode> $nodes
     *
     * @return list<PageNode>
     */
    public static function children(array $nodes, ?string $parentId): array
    {
        $children = array_values(array_filter($nodes, static fn(PageNode $node): bool => $node->data->parentId === $parentId));
        usort($children, static fn(PageNode $a, PageNode $b): int => [$a->data->position, $a->id] <=> [$b->data->position, $b->id]);

        return $children;
    }

    /**
     * @param array<string, PageData> $published
     * @param array<string, PageData> $drafts
     *
     * @return array<string, PageNode>
     */
    private function merge(array $published, array $drafts): array
    {
        $nodes = [];

        foreach ($published + $drafts as $id => $data) {
            $draft = $drafts[$id] ?? null;
            $nodes[$id] = new PageNode($id, $draft ?? $data, self::status($draft, $published[$id] ?? null));
        }

        return $nodes;
    }

    /**
     * @return array<string, PageData>
     */
    private function publishedPages(): array
    {
        $translations = [];

        foreach ($this->translations->fetch() as $translation) {
            $translations[$translation->pageId][] = $translation;
        }

        $pages = [];

        foreach ($this->pages->fetch() as $page) {
            $pages[$page->id] = PageData::fromPage($page, ...$translations[$page->id] ?? []);
        }

        return $pages;
    }

    /**
     * @return array<string, PageData>
     */
    private function drafts(): array
    {
        return array_map(static fn(Revision $draft): PageData => PageData::fromArray($draft->data), $this->revisions->drafts(self::TYPE));
    }

    private static function status(?PageData $draft, ?PageData $published): PageStatus
    {
        return match (true) {
            null === $published => PageStatus::Draft,
            null === $draft => PageStatus::Published,
            default => PageStatus::Changed,
        };
    }
}
