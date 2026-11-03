<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;

/**
 * @implements Query<list<GroupSummary>>
 */
#[AsQuery('category-group.list', 'The groups of categories with the kinds of content they apply to and how many categories each has', signedIn: true)]
final readonly class ListGroups implements Query
{
    public function __construct(
        #[Description('Only the groups for this type, e.g. "frootbox/news/article"')]
        public ?string $type = null,
    ) {}

    /**
     * @return list<GroupSummary>
     */
    public function handle(CategoryGroups $groups, Categories $categories, CategoryEditor $editor): array
    {
        $list = [];

        foreach (null === $this->type ? $groups->all() : $groups->forType($this->type) as $group) {
            $list[] = new GroupSummary(
                $group->key,
                $groups->title($group),
                array_map(static fn(string $type): array => ['type' => $type, 'label' => $groups->typeLabel($type)], $group->types),
                $group->source,
                $categories->count(['groupKey' => $group->key]),
                $editor->missingPresets($group),
            );
        }

        return $list;
    }
}
