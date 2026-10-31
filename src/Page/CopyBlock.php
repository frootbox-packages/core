<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
 * Puts a copy of a block with everything inside it right after it, with
 * new ids, in the draft (decision 0123).
 *
 * @implements Action<BlockChanged>
 */
#[AsAction('page.copy-block', 'Copies a block of a page with everything inside it right after it, in its draft; answers with the id of the copy')]
final readonly class CopyBlock implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation to change, e.g. "de"')]
        public string $language,
        #[Description('Id of the block to copy')]
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
        $blocks = ContentBlocks::of('page.copy-block', $components, $page, $this->language);
        $way = $blocks->find($this->block);
        $list = \array_slice($way, 0, -1);
        $index = (int) end($way) + 1;

        $details = $blocks->insert($list, $index, ContentBlocks::withoutIds($blocks->block($way)))->save($editor, $this->id, $page, $this->language);
        $saved = ($details->draft ?? $page)->translations[$this->language]->content ?? [];

        return new BlockChanged($this->id, $this->language, ContentBlocks::idAt($saved, $list, $index), $journal->currentEntryId());
    }
}
