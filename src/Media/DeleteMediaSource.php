<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * Decision 0177.
 *
 * @implements Action<MediumDetails>
 */
#[AsAction('media.source-delete', 'Deletes a further file of a video; the file of the medium itself stays')]
final readonly class DeleteMediaSource implements Action, Scoped
{
    public function __construct(
        #[Description('The video')]
        public string $id,
        #[Description('The id of the file, from sources in media.show')]
        public string $sourceId,
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

    public function handle(MediaEditor $editor): MediumDetails
    {
        return $editor->deleteSource('media.source-delete', $this->id, $this->sourceId);
    }
}
