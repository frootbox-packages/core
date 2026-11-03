<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Support\Message;

/**
 * @implements Query<list<CategoryItem>>
 */
#[AsQuery('category.list', 'The categories of a group in the order of the tree, each with its depth', public: true)]
final readonly class ListCategories implements Query
{
    public function __construct(
        #[Description('The group, e.g. "frootbox/news/categories"')]
        public string $group,
    ) {}

    /**
     * @return list<CategoryItem>
     */
    public function handle(Categories $categories, CategoryGroups $groups): array
    {
        if (null === $groups->get($this->group)) {
            throw new InvalidInputException('category.list', ['/group' => new Message('There is no group of categories "{group}".', ['group' => $this->group])]);
        }

        return $categories->tree($this->group);
    }
}
