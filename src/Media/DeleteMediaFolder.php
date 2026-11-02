<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Action<bool>
 */
#[AsAction('media.folder-delete', 'Deletes an empty folder of the media library')]
final readonly class DeleteMediaFolder implements Action, Scoped
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

    public function handle(MediaEditor $editor): bool
    {
        $editor->deleteFolder('media.folder-delete', $this->id);

        return true;
    }
}
