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
#[AsAction('media.source-add', 'Adds a further file to a video, the same film in another format or codec, e.g. WebM with VP9 or MP4 with HEVC next to MP4 with H.264. Browsers take the first file they can play; the order follows the codec, the most efficient first and H.264 last. It counts at once, without draft')]
final readonly class AddMediaSource implements Action, Scoped
{
    public function __construct(
        #[Description('The video')]
        public string $id,
        #[Description('The token of the new file from the upload address')]
        public string $upload,
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
        return $editor->addSource('media.source-add', $this->id, $this->upload);
    }
}
