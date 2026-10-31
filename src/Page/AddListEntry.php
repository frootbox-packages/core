<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Core\Support\Message;
use Frootbox\Database\Ulid;
use stdClass;

/**
 * Adds an entry to a list field, in the draft (decision 0123).
 *
 * @implements Action<BlockChanged>
 */
#[AsAction('page.add-list-entry', 'Adds an entry to a list field of the layout or of a block of a page, in its draft')]
final readonly class AddListEntry implements Action, Scoped
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation to change, e.g. "de"')]
        public string $language,
        #[Description('Path of the list field, e.g. "slides"')]
        #[Pattern(ListEntries::LIST_PATTERN)]
        public string $path,
        #[Description('Id of the block; left out for a field of the layout')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public ?string $block = null,
        #[Description('Position of the new entry; left out for the end')]
        #[Range(min: 0)]
        public ?int $index = null,
        #[Description('Values of the fields of the entry to begin with')]
        public array $fields = [],
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
        $list = ListEntries::of('page.add-list-entry', $components, $editor->current($this->id), $this->language, $this->block, $this->path);
        $entries = $list->entries;
        $index = $this->index ?? \count($entries);

        if ($index > \count($entries)) {
            throw new InvalidInputException('page.add-list-entry', ['/index' => new Message('The list has {count} entries.', ['count' => \count($entries)])]);
        }

        // An entry without values stays an object in JSON.
        array_splice($entries, $index, 0, [[] === $this->fields ? new stdClass() : $this->fields]);
        $list->save($editor, $this->id, $entries);

        return new BlockChanged($this->id, $this->language, $this->block, $journal->currentEntryId());
    }
}
