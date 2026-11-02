<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Page\PageReader;

/**
 * @implements Query<list<MediumUsage>>
 */
#[AsQuery('media.usages', 'Returns where a medium is used: pages and the fields of the website, by language, in the draft or published', permission: 'media.list')]
final readonly class ListMediumUsages implements Query, Scoped
{
    public function __construct(
        public string $id,
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

    /**
     * @return list<MediumUsage>
     */
    public function handle(Media $media, MediaUsages $usages, PageReader $pages): array
    {
        $media->getById($this->id);
        $places = [];

        foreach ($usages->ofMedium($this->id) as $usage) {
            $key = $usage->ownerType . '|' . $usage->ownerId . '|' . $usage->language;
            $places[$key] ??= ['type' => $usage->ownerType, 'id' => $usage->ownerId, 'language' => $usage->language, 'draft' => false, 'published' => false];
            $places[$key][$usage->published ? 'published' : 'draft'] = true;
        }

        $found = [];

        foreach ($places as $place) {
            $title = '';

            if (UsageIndex::PAGE === $place['type']) {
                $page = $pages->draft($place['id']) ?? $pages->published($place['id']);
                $title = $page?->translations[$place['language']]->title ?? '';
            }

            $found[] = new MediumUsage($place['type'], $place['id'], $place['language'], $title, $place['draft'], $place['published']);
        }

        return $found;
    }
}
