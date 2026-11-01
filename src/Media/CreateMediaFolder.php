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
#[AsAction('media.folder-create', 'Creates a folder in the media library')]
final readonly class CreateMediaFolder implements Action, Scoped
{
    public function __construct(
        public string $name,
        #[Description('The folder it goes into, none for the top')]
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
        return $editor->createFolder('media.folder-create', $this->name, $this->parentId);
    }
}
