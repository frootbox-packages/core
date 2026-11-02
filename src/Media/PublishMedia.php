<?php

/*
 * SPDX-License-Identifier: MIT
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
 * @implements Action<list<MediumDetails>>
 */
#[AsAction('media.publish', 'Publishes the drafts of one or more media: new texts and focus point show on every page that uses them')]
final readonly class PublishMedia implements Action, Scoped
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        #[ListOf('string')]
        #[Description('Ids of media with a draft; media.usages tells where each one is used')]
        public array $ids,
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

    /**
     * @return list<MediumDetails>
     */
    public function handle(MediaEditor $editor): array
    {
        return $editor->publishDrafts('media.publish', $this->ids);
    }
}
