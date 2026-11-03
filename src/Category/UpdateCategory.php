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
use Frootbox\Database\Ulid;

/**
 * @implements Action<Category>
 */
#[AsAction('category.update', 'Changes the title, slug and description of a category')]
final readonly class UpdateCategory implements Action
{
    /**
     * @param array<mixed> $translations
     */
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Title, slug and description by language; languages left out stay, null or empty fields remove one')]
        public array $translations,
    ) {}

    public function handle(CategoryEditor $editor): Category
    {
        return $editor->update('category.update', $this->id, $this->translations);
    }
}
