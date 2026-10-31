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
 * Removes an entry of a list field, in the draft (decision 0123).
 *
 * @implements Action<BlockChanged>
 */
#[AsAction('page.remove-list-entry', 'Removes an entry of a list field of a page, in its draft')]
final readonly class RemoveListEntry implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation to change, e.g. "de"')]
        public string $language,
        #[Description('Path of the entry, e.g. "slides/2"')]
        #[Pattern(ListEntries::ENTRY_PATTERN)]
        public string $path,
        #[Description('Id of the block; left out for a field of the layout')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public ?string $block = null,
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
        [$path, $index] = ListEntries::split($this->path);
        $list = ListEntries::of('page.remove-list-entry', $components, $editor->current($this->id), $this->language, $this->block, $path);
        $list->check($index);

        $entries = $list->entries;
        array_splice($entries, $index, 1);
        $list->save($editor, $this->id, $entries);

        return new BlockChanged($this->id, $this->language, $this->block, $journal->currentEntryId());
    }
}
