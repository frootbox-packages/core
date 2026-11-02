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

/**
 * @implements Query<MediumDetails>
 */
#[AsQuery('media.show', 'Returns a medium with texts, tags, focus point and a preview address', permission: 'media.list')]
final readonly class ShowMedium implements Query, Scoped
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

    public function handle(Media $media, MediaEditor $editor): MediumDetails
    {
        return $editor->details($media->getById($this->id));
    }
}
