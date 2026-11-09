<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;
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
        private PageProtections $protections,
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

        $version = $this->revisions->published(self::TYPE, $id);
        $nodes = $this->nodes();
        $protection = $this->protections->all()[$id] ?? null;

        return new PageDetails(
            $id,
            self::status($draft, $published),
            $version?->version,
            $draft,
            $published,
            null === $draft ? null : $this->revisions->draft(self::TYPE, $id)?->publishAt,
            $version?->unpublishAt,
            locked: $nodes[$id]->locked ?? Reach::None,
            visibility: $this->visibility($nodes)[$id] ?? new PageVisibility(),
            protected: $protection->reach ?? Reach::None,
            protectedSince: $protection->updatedAt ?? $protection?->createdAt,
        );
    }

    /**
     * All pages, drafts over published state.
     *
     * @return array<string, PageNode> by id
     */
    public function nodes(): array
    {
        $drafts = $this->revisions->drafts(self::TYPE);

        return $this->merge(
            $this->publishedPages(),
            array_map(static fn(Revision $draft): PageData => PageData::fromArray($draft->data), $drafts),
            array_map(static fn(Revision $draft): ?DateTimeImmutable => $draft->publishAt, $drafts),
            $this->locks(),
            $this->passwords(),
        );
    }

    /**
     * What applies to every page (decision 0117).
     *
     * @param array<string, PageNode>|null $nodes from nodes(), if already read
     *
     * @return array<string, PageVisibility> by id
     */
    public function visibility(?array $nodes = null): array
    {
        return PageVisibility::of($nodes ?? $this->nodes(), $this->nodesAfterPublishing([]));
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
        return $this->merge($this->publishedPages(), array_intersect_key($this->drafts(), array_flip($ids)), [], $this->locks(), $this->passwords());
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
     * @param array<string, PageData>                $published
     * @param array<string, PageData>                $drafts
     * @param array<string, DateTimeImmutable|null> $publishAt of the drafts
     * @param array<string, Reach>                   $locks     of the published pages
     * @param array<string, Reach>                   $passwords of the pages with one
     *
     * @return array<string, PageNode>
     */
    private function merge(array $published, array $drafts, array $publishAt = [], array $locks = [], array $passwords = []): array
    {
        $nodes = [];

        foreach ($published + $drafts as $id => $data) {
            $draft = $drafts[$id] ?? null;
            $nodes[$id] = new PageNode($id, $draft ?? $data, self::status($draft, $published[$id] ?? null), $publishAt[$id] ?? null, $locks[$id] ?? Reach::None, $passwords[$id] ?? Reach::None);
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
     * The locks of the published pages that have one.
     *
     * @return array<string, Reach>
     */
    private function locks(): array
    {
        $locks = [];

        foreach ($this->pages->fetch(['where' => ['locked' => [Reach::Page->value, Reach::Branch->value]]]) as $page) {
            $locks[$page->id] = $page->locked;
        }

        return $locks;
    }

    /**
     * The reach of the pages with a password (decision 0358).
     *
     * @return array<string, Reach>
     */
    private function passwords(): array
    {
        return array_map(static fn(PageProtection $protection): Reach => $protection->reach, $this->protections->all());
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
