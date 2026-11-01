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
#[AsQuery('media.list', 'Returns media of the library, newest first, filtered by folder, tag or a search in file names and texts')]
final readonly class ListMedia implements Query, Scoped
{
    public function __construct(
        #[Description('Only this folder, "" for the top; none for all')]
        public ?string $folderId = null,
        public ?string $tag = null,
        #[Description('Part of the file name, alternative text or title')]
        public ?string $search = null,
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

    public function handle(Media $media, MediaTexts $texts, MediaTags $tags, MediaEditor $editor): MediaPage
    {
        $where = [];

        if (null !== $this->folderId) {
            $where['folderId'] = '' === $this->folderId ? null : $this->folderId;
        }

        if (null !== $this->tag && '' !== trim($this->tag)) {
            $where['id'] = self::ids($tags->fetch(['where' => ['tag' => trim($this->tag)]])->all());
        }

        $search = null === $this->search ? '' : trim($this->search);

        if ('' !== $search) {
            $like = '%' . $search . '%';
            $found = self::ids($texts->fetch(['where' => [Where::any(['alt like' => $like], ['title like' => $like])]])->all());
            $where[] = Where::any(['fileName like' => $like], ['id' => $found]);
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
