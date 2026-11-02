<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Database\Ulid;

/**
 * Moves a block within its area or into another one, in the draft
 * (decision 0123). The target is named by ids, as in page.add-block.
 *
 * @implements Action<BlockChanged>
 */
#[AsAction('page.move-block', 'Moves a block of a page to another place, also into another area, in its draft')]
final readonly class MoveBlock implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation to change, e.g. "de"')]
        public string $language,
        #[Description('Id of the block to move')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public string $block,
        #[Description('Name of the target area, e.g. "main"')]
        public string $area,
        #[Description('Id of the block that holds the target area; left out for an area of the layout')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public ?string $parent = null,
        #[Description('Id of the block after which it goes; left out for the start of the area')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public ?string $after = null,
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
        $blocks = ContentBlocks::of('page.move-block', $components, $page, $this->language);
        $way = $blocks->find($this->block);
        $block = $blocks->block($way);

        foreach (['/parent' => $this->parent, '/after' => $this->after] as $key => $target) {
            if (null !== $target && ($target === $this->block || null !== ContentBlocks::locate($block, $target))) {
                throw new InvalidInputException('page.move-block', [$key => 'A block cannot go into or next to itself.']);
            }
        }

        $without = $blocks->remove($way);
        [$list, $index] = $without->place($this->parent, $this->area, $this->after);
        $without->checkAllowed($list, \is_string($block['block'] ?? null) ? $block['block'] : '');
        $without->insert($list, $index, $block)->save($editor, $this->id, $page, $this->language);

        return new BlockChanged($this->id, $this->language, $this->block, $journal->currentEntryId());
    }
}
