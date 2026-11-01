<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Query<list<FolderSummary>>
 */
#[AsQuery('media.folders', 'Returns all folders of the media library by name, with the number of media in each', permission: 'media.list')]
final readonly class ListMediaFolders implements Query, Scoped
{
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

    /**
     * @return list<FolderSummary>
     */
    public function handle(MediaFolders $folders, Media $media): array
    {
        $summaries = [];

        foreach ($folders->fetch(['order' => 'name']) as $folder) {
            $summaries[] = new FolderSummary($folder->id, $folder->parentId, $folder->name, $media->count(['folderId' => $folder->id]));
        }

        return $summaries;
    }
}
