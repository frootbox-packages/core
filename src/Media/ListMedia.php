<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Database\Where;

/**
 * @implements Query<MediaPage>
 */
#[AsQuery('media.list', 'Returns media of the library, newest first, filtered by folder, tag, a search in file names and texts, only those used nowhere, only those with a draft, only those a page uses, only images, videos or documents')]
final readonly class ListMedia implements Query, Scoped
{
    public function __construct(
        #[Description('Only this folder, "" for the top; none for all')]
        public ?string $folderId = null,
        public ?string $tag = null,
        #[Description('Part of the file name, alternative text or title')]
        public ?string $search = null,
        #[Description('Only media that no page and no field of the website uses, neither in a draft nor published')]
        public bool $unused = false,
        #[Description('Only media with a draft that is not published yet')]
        public bool $changed = false,
        #[Description('Only media this page uses: its draft, or the published state if it has none')]
        public ?string $pageId = null,
        #[Description('"image", "video" or "document" for only images, videos (decision 0177) or documents (decision 0179); none for all')]
        public ?string $kind = null,
        #[Range(1)]
        public int $page = 1,
        #[Range(1, 200)]
        public int $perPage = 48,
    ) {}

    /**
     * The media library is one for the website: the permission in any
     * branch is enough.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [];
    }

    public function handle(Media $media, MediaTexts $texts, MediaTags $tags, MediaEditor $editor, UsageIndex $usages, MediaDrafts $drafts): MediaPage
    {
        $where = [];

        if (null !== $this->folderId) {
            $where['folderId'] = '' === $this->folderId ? null : $this->folderId;
        }

        if (null !== $this->tag && '' !== trim($this->tag)) {
            $where['id'] = self::ids($tags->fetch(['where' => ['tag' => trim($this->tag)]])->all());
        }

        $types = match ($this->kind) {
            'image' => Images::TYPES,
            'video' => Videos::TYPES,
            'document' => Documents::TYPES,
            default => null,
        };

        if (null !== $types) {
            $where['type'] = array_keys($types);
        }

        $search = null === $this->search ? '' : trim($this->search);

        if ('' !== $search) {
            $like = '%' . $search . '%';
            $found = self::ids($texts->fetch(['where' => [Where::any(['alt like' => $like], ['title like' => $like])]])->all());
            $where[] = Where::any(['fileName like' => $like], ['id' => $found]);
        }

        if ($this->unused) {
            $used = $usages->used();

            if ([] !== $used) {
                $where['id not in'] = $used;
            }
        }

        $only = null;

        if ($this->changed) {
            $only = $drafts->ids();
        }

        if (null !== $this->pageId) {
            $onPage = $usages->ofPage($this->pageId);
            $only = null === $only ? $onPage : array_values(array_intersect($only, $onPage));
        }

        if ([] === $only) {
            return new MediaPage([], 0, $this->page, $this->perPage);
        }

        if (null !== $only) {
            $where['id in'] = $only;
        }

        $result = $media->paginate(['where' => $where, 'order' => ['createdAt' => 'desc', 'id' => 'desc']], $this->page, $this->perPage);

        return new MediaPage(
            $result->items->map($editor->details(...)),
            $result->total,
            $this->page,
            $this->perPage,
        );
    }

    /**
     * @param list<MediaText|MediaTag> $rows
     *
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_values(array_unique(array_map(static fn(MediaText|MediaTag $row): string => $row->mediaId, $rows)));
    }
}
