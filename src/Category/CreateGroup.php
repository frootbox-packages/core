<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;

/**
 * @implements Action<AdminGroup>
 */
#[AsAction('category-group.create', 'Adds a group of categories for one or more kinds of content, e.g. "Abteilung" for news and jobs')]
final readonly class CreateGroup implements Action
{
    /**
     * @param list<string> $types
     */
    public function __construct(
        #[Length(1, 100)]
        public string $title,
        #[Description('The types it applies to, e.g. ["frootbox/news/article", "frootbox/jobs/job"]')]
        public array $types,
    ) {}

    public function handle(GroupEditor $editor): AdminGroup
    {
        return $editor->create('category-group.create', $this->title, $this->types);
    }
}
