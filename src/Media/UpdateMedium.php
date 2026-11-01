<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Action<MediumDetails>
 */
#[AsAction('media.update', 'Changes folder, focus point, texts or tags of a medium; what is left out stays. Folder and tags count at once; focus point and texts of a public medium go into its draft until media.publish')]
final readonly class UpdateMedium implements Action, Scoped
{
    /**
     * @param array<mixed>|null $texts
     * @param list<string>|null $tags
     */
    public function __construct(
        public string $id,
        #[Description('Another folder, "" for the top')]
        public ?string $folderId = null,
        #[Range(0, 1)]
        #[Description('The focus point from the left, 0 to 1; the image is cut around it')]
        public ?float $focusX = null,
        #[Range(0, 1)]
        #[Description('The focus point from the top, 0 to 1')]
        public ?float $focusY = null,
        #[Description('Alternative text and title by language; languages given are replaced')]
        public ?array $texts = null,
        #[ListOf('string')]
        #[Description('All tags of the medium')]
        public ?array $tags = null,
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
        return $editor->update('media.update', $this->id, $this->folderId, $this->focusX, $this->focusY, $this->texts, $this->tags);
    }
}
