<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;

/**
 * @implements Action<list<PageDetails>>
 */
#[AsAction('page.publish', 'Publishes the drafts of one or more pages together')]
final readonly class PublishPages implements Action, Scoped
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        #[ListOf('string')]
        #[Description('Ids of the pages; a parent that is only a draft must be in the list, too')]
        public array $ids,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return array_map(Scope::page(...), $this->ids);
    }

    /**
     * @return list<PageDetails>
     */
    public function handle(PageEditor $editor): array
    {
        return $editor->publish('page.publish', $this->ids);
    }
}
