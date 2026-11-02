<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\I18n\Translator;

/**
 * The pages of the help the user may read, in their order (decision
 * 0215), in the language of the backend.
 *
 * @implements Query<list<HelpEntry>>
 */
#[AsQuery('help.list', 'Lists the pages of the help the user may read, optionally only those about a screen, block, action or field type', signedIn: true)]
final readonly class ListHelp implements Query
{
    public function __construct(
        #[Length(max: 200)]
        #[Description('Only pages about this, e.g. "screen:backend.pages" or "action:page.publish"')]
        public ?string $about = null,
    ) {}

    /**
     * @return list<HelpEntry>
     */
    public function handle(HelpLibrary $library, HelpAccess $access, CurrentContext $context, Translator $translator): array
    {
        $actor = $context->get()->actor;
        $language = $translator->language();
        $entries = [];

        foreach ($library->all() as $topic) {
            if ((null === $this->about || \in_array($this->about, $topic->about, true)) && $access->allows($actor, $topic)) {
                $entries[] = new HelpEntry($topic->id, $topic->title($language), $topic->package, $topic->about);
            }
        }

        return $entries;
    }
}
