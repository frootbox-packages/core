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
use Frootbox\Core\Component\Variant;
use Frootbox\Core\Support\Message;
use Frootbox\Database\Ulid;

/**
 * Chooses the variant and the view options of a block, in the draft
 * (decision 0123). Fields stay as they are.
 *
 * @implements Action<BlockChanged>
 */
#[AsAction('page.update-block', 'Changes the variant or the view options of a block of a page, in its draft')]
final readonly class UpdateBlock implements Action, Scoped
{
    /**
     * @param array<string, mixed>|null $options
     */
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation to change, e.g. "de"')]
        public string $language,
        #[Description('Id of the block')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public string $block,
        #[Description('The new variant, e.g. "Wide" or "Default"; left out to keep it')]
        public ?string $variant = null,
        #[Description('All chosen view options; left out to keep them')]
        public ?array $options = null,
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
        $blocks = ContentBlocks::of('page.update-block', $components, $page, $this->language);
        $way = $blocks->find($this->block);
        $block = $blocks->block($way);
        $component = $blocks->component($way);

        if (null !== $this->variant) {
            if (null !== $component && !isset($component->variants[$this->variant])) {
                throw new InvalidInputException('page.update-block', ['/variant' => new Message('The block has no variant "{variant}".', ['variant' => $this->variant])]);
            }

            unset($block['variant']);

            if (Variant::DEFAULT !== $this->variant) {
                $block['variant'] = $this->variant;
            }
        }

        if (null !== $this->options) {
            unset($block['options']);

            if ([] !== $this->options) {
                $block['options'] = $this->options;
            }
        }

        $blocks->replace($way, $block)->save($editor, $this->id, $page, $this->language);

        return new BlockChanged($this->id, $this->language, $this->block, $journal->currentEntryId());
    }
}
