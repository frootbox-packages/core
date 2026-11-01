<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Action<MediaFolder>
 */
#[AsAction('media.folder-update', 'Renames a folder of the media library or moves it')]
final readonly class UpdateMediaFolder implements Action, Scoped
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        #[Description('Another folder it goes into, "" for the top')]
        public ?string $parentId = null,
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

    public function handle(MediaEditor $editor): MediaFolder
    {
        return $editor->updateFolder('media.folder-update', $this->id, $this->name, $this->parentId);
    }
}
