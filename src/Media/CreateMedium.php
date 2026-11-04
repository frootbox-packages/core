<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Action<MediumDetails>
 */
#[AsAction('media.create', 'Adds an image (JPEG, PNG, WebP, GIF, SVG), a video or a document from an upload to the media library; it becomes public with the first published page that uses it. Is the same file already there, it gives that medium with "existing": true and keeps its folder, texts and tags')]
final readonly class CreateMedium implements Action, Scoped
{
    /**
     * @param array<mixed> $texts
     * @param list<string> $tags
     */
    public function __construct(
        #[Description('The token from POST /api/v1/uploads, or from an "upload" step of a seed file')]
        public string $upload,
        #[Description('The folder, none for the top')]
        public ?string $folderId = null,
        #[Description('Alternative text and title by language, e.g. {"de": {"alt": "Röster am Hafen", "title": ""}}')]
        public array $texts = [],
        #[ListOf('string')]
        public array $tags = [],
        #[Length(max: 300)]
        #[Description('Photographer or image agency, e.g. "Anna Muster / Bildagentur"; left out, it comes from the IPTC or EXIF data of the file, if there')]
        public ?string $copyright = null,
        #[Description('Whether an AI made ("generated") or changed ("edited") it, shown as a badge on the image; left out, it comes from IPTC DigitalSourceType in the file, if there (decision 0251)')]
        public ?AiOrigin $ai = null,
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
        return $editor->create('media.create', $this->upload, $this->folderId, $this->texts, $this->tags, $this->copyright, $this->ai);
    }
}
