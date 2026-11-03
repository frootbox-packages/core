<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Database\Ulid;

/**
 * @implements Action<Category>
 */
#[AsAction('category.move', 'Puts a category below another one of its group or at the top')]
final readonly class MoveCategory implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Pattern(Ulid::PATTERN)]
        #[Description('The category above, null for the top')]
        public ?string $parentId = null,
        #[Range(min: 0)]
        #[Description('Place among the new siblings, 0 for the first; null for the last')]
        public ?int $position = null,
    ) {}

    public function handle(CategoryEditor $editor): Category
    {
        return $editor->move('category.move', $this->id, $this->parentId, $this->position);
    }
}
