<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Database\Ulid;

/**
 * Removes a block with everything inside it from the draft (decision 0123).
 *
 * @implements Action<BlockChanged>
 */
#[AsAction('page.remove-block', 'Removes a block of a page with everything inside it, in its draft')]
final readonly class RemoveBlock implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation to change, e.g. "de"')]
        public string $language,
        #[Description('Id of the block to remove')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public string $block,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(PageEditor $editor, Components $components, Journal $journal): BlockChanged
    {
        $page = $editor->current($this->id);
        $blocks = ContentBlocks::of('page.remove-block', $components, $page, $this->language);
        $way = $blocks->find($this->block);
        $blocks->assertPlaced($way);
        $blocks->remove($way)->save($editor, $this->id, $page, $this->language);

        return new BlockChanged($this->id, $this->language, null, $journal->currentEntryId());
    }
}
