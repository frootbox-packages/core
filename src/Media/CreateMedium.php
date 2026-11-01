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
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Action<MediumDetails>
 */
#[AsAction('media.create', 'Adds an image (JPEG, PNG, WebP, GIF) from an upload to the media library; it becomes public with the first published page that uses it')]
final readonly class CreateMedium implements Action, Scoped
{
    /**
     * @param array<mixed> $texts
     * @param list<string> $tags
     */
    public function __construct(
        #[Description('The token from POST /api/v1/uploads')]
        public string $upload,
        #[Description('The folder, none for the top')]
        public ?string $folderId = null,
        #[Description('Alternative text and title by language, e.g. {"de": {"alt": "Röster am Hafen", "title": ""}}')]
        public array $texts = [],
        #[ListOf('string')]
        public array $tags = [],
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
        return $editor->create('media.create', $this->upload, $this->folderId, $this->texts, $this->tags);
    }
}
