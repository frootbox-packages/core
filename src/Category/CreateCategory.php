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
#[AsAction('category.create', 'Adds a category to a group, at the top or below another one')]
final readonly class CreateCategory implements Action
{
    /**
     * @param array<mixed> $translations
     */
    public function __construct(
        #[Description('The group, e.g. "frootbox/news/categories"')]
        public string $group,
        #[Description('Title, slug and description by language: {"de": {"title": "Kurse"}}; the slug is made from the title if left out')]
        public array $translations,
        #[Pattern(Ulid::PATTERN)]
        #[Description('The category above, left out for the top')]
        public ?string $parentId = null,
        #[Range(min: 0)]
        #[Description('Place among the siblings, 0 for the first; left out for the last')]
        public ?int $position = null,
    ) {}

    public function handle(CategoryEditor $editor): Category
    {
        return $editor->create('category.create', $this->group, $this->translations, $this->parentId, $this->position);
    }
}
