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
 * @implements Action<MediumDetails>
 */
#[AsAction('media.replace', 'Gives a medium a new file for every place that uses it; the focus point stays. A public medium gets it in its draft until media.publish. To change the image at one place only, upload it as a new medium with media.create instead')]
final readonly class ReplaceMedium implements Action, Scoped
{
    public function __construct(
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
        return $editor->replace('media.replace', $this->id, $this->upload);
    }
}
