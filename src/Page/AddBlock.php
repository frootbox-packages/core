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
 * Puts a new block into an area of a page, in its draft (decision 0123).
 *
 * @implements Action<BlockChanged>
 */
#[AsAction('page.add-block', 'Adds a block to an area of the layout or of a block of a page, in its draft; answers with the id of the new block')]
final readonly class AddBlock implements Action, Scoped
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $fields
     */
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation to change, e.g. "de"')]
        public string $language,
        #[Description('Full name of the block, e.g. "acme/website/Teaser"')]
        public string $name,
        #[Description('Name of the area, e.g. "main"')]
        public string $area,
        #[Description('Id of the block that holds the area; left out for an area of the layout')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public ?string $parent = null,
        #[Description('Id of the block in the area after which the new one goes; left out for the start')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public ?string $after = null,
        #[Description('Variant of the block, e.g. "Wide"; left out for Default')]
        public ?string $variant = null,
        #[Description('Chosen view options of the variant')]
        public array $options = [],
        #[Description('Values of the fields to begin with')]
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
        $page = $editor->current($this->id);
        $blocks = ContentBlocks::of('page.add-block', $components, $page, $this->language);
        [$list, $index] = $blocks->place($this->parent, $this->area, $this->after);
        $blocks->checkAllowed($list, $this->name);

        $block = ['block' => $this->name];

        if (null !== $this->variant) {
            $block['variant'] = $this->variant;
        }

        if ([] !== $this->options) {
            $block['options'] = $this->options;
        }

        if ([] !== $this->fields) {
            $block['fields'] = $this->fields;
        }

        $details = $blocks->insert($list, $index, $block)->save($editor, $this->id, $page, $this->language);
        $saved = ($details->draft ?? $page)->translations[$this->language]->content ?? [];

        return new BlockChanged($this->id, $this->language, ContentBlocks::idAt($saved, $list, $index), $journal->currentEntryId());
    }
}
