<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\Pages;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Site\SiteFields;
use Frootbox\Core\Theme\ThemeChain;

/**
 * Keeps the index of where media are used (decision 0146). The editors
 * of pages and of the fields of the website call it after every change
 * of a draft or of the published state. Old versions and the journal do
 * not count. Everything goes through the repository, so undo brings the
 * index back with the content.
 */
final readonly class UsageIndex
{
    public const string PAGE = 'page';

    public const string SITE = 'site';

    public function __construct(
        private MediaUsages $usages,
        private MediaReferences $references,
        private PageReader $pages,
        private Pages $pageRows,
        private Revisions $revisions,
        private SiteFields $site,
        private ThemeChain $themes,
    ) {}

    /**
     * Brings the rows of a page up to date with its draft and its
     * published state; a deleted page has none.
     */
    public function page(string $id): void
    {
        $draft = $this->pages->draft($id);
        $published = $this->pages->published($id);

        $this->write(
            self::PAGE,
            $id,
            null === $draft ? [] : $this->references->inPageByLanguage($draft),
            null === $published ? [] : $this->references->inPageByLanguage($published),
        );
    }

    /**
     * Brings the rows of the fields of the active theme up to date.
     */
    public function site(): void
    {
        $theme = $this->themes->active();

        if (null === $theme) {
            return;
        }

        $fields = $this->site->fields();
        $draft = $this->revisions->draft(SiteFields::TYPE, $theme->name);
        $published = $this->revisions->published(SiteFields::TYPE, $theme->name);

        $this->write(
            self::SITE,
            $theme->name,
            null === $draft ? [] : $this->references->inFieldsByLanguage($fields, SiteFields::languages($draft)),
            null === $published ? [] : $this->references->inFieldsByLanguage($fields, SiteFields::languages($published)),
        );
    }

    /**
     * Builds the whole index anew from the content.
     *
     * @return int How many rows changed
     */
    public function rebuild(): int
    {
        $before = $this->rows();
        $pages = [];
        $others = [];

        foreach ($this->pageRows->fetch() as $page) {
            $pages[$page->id] = true;
        }

        foreach ($this->revisions->drafts(PageReader::TYPE) as $draft) {
            $pages[$draft->entityId] = true;
        }

        foreach ($this->usages->fetch() as $usage) {
            if (self::PAGE === $usage->ownerType) {
                $pages[$usage->ownerId] = true;
            } else {
                $others[$usage->ownerType . '|' . $usage->ownerId] = [$usage->ownerType, $usage->ownerId];
            }
        }

        foreach (array_keys($pages) as $id) {
            $this->page($id);
        }

        $theme = $this->themes->active()?->name;

        foreach ($others as [$type, $id]) {
            // Only the active theme describes its fields; others are left over.
            if (self::SITE !== $type || $id !== $theme) {
                $this->write($type, $id, [], []);
            }
        }

        $this->site();
        $after = $this->rows();

        return \count(array_diff_key($before, $after)) + \count(array_diff_key($after, $before));
    }

    /**
     * Ids of all media used somewhere, in a draft or published.
     *
     * @return list<string>
     */
    public function used(): array
    {
        return array_values(array_unique($this->usages->fetch()->map(static fn(MediaUsage $usage): string => $usage->mediaId)));
    }

    /**
     * @param array<string, list<string>> $draft     Media by language
     * @param array<string, list<string>> $published Media by language
     */
    private function write(string $type, string $id, array $draft, array $published): void
    {
        $wanted = [];

        foreach ([[$draft, false], [$published, true]] as [$byLanguage, $isPublished]) {
            foreach ($byLanguage as $language => $ids) {
                foreach ($ids as $mediaId) {
                    $wanted[MediaUsage::keyOf($mediaId, $language, $isPublished)] = [$mediaId, $language, $isPublished];
                }
            }
        }

        foreach ($this->usages->ofOwner($type, $id) as $usage) {
            if (isset($wanted[$usage->key()])) {
                unset($wanted[$usage->key()]);
            } else {
                $this->usages->delete($usage->id);
            }
        }

        foreach ($wanted as [$mediaId, $language, $isPublished]) {
            $this->usages->insert(['mediaId' => $mediaId, 'ownerType' => $type, 'ownerId' => $id, 'language' => $language, 'published' => $isPublished]);
        }
    }

    /**
     * @return array<string, true>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->usages->fetch() as $usage) {
            $rows[$usage->ownerType . '|' . $usage->ownerId . '|' . $usage->key()] = true;
        }

        return $rows;
    }
}
