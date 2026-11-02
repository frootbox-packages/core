<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Database\Ulid;

/**
 * Moving changes only the draft of the moved page (decision 0101).
 *
 * @implements Action<PageDetails>
 */
#[AsAction('page.move', 'Moves a page to another parent or place in its draft')]
final readonly class MovePage implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Pattern(Ulid::PATTERN)]
        #[Description('The new parent page')]
        public string $parentId,
        #[Range(min: 0)]
        #[Description('Place among the pages next to it, 0 for the first; left out for the last')]
        public ?int $position = null,
    ) {}

    /**
     * The page and its new parent; the top of a branch stays where it is.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id), Scope::page($this->parentId)];
    }

    public function handle(PageEditor $editor): PageDetails
    {
        $current = $editor->current($this->id);

        if (null === $current->parentId) {
            throw new InvalidInputException('page.move', ['/id' => 'The start page cannot be moved.']);
        }

        $position = $current->parentId === $this->parentId && null === $this->position
            ? $current->position
            : $editor->position($this->id, $this->parentId, $this->position);

        return $editor->saveDraft('page.move', $this->id, $current->with(parentId: $this->parentId, position: $position));
    }
}
