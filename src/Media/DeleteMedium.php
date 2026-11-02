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
#[AsAction('media.delete', 'Deletes a medium that no published page or field uses (409 otherwise, see media.usages); drafts that use it show nothing there any more')]
final readonly class DeleteMedium implements Action, Scoped
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
        $editor->delete($this->id);

        return true;
    }
}
