<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Pattern;

/**
 * @implements Query<NavigationDetails>
 */
#[AsQuery('navigation.show', 'Returns a navigation with its draft and its published items by language')]
final readonly class ShowNavigation implements Query
{
    public function __construct(
        #[Pattern(Definition::NAME_PATTERN)]
        public string $name,
    ) {}

    public function handle(NavigationEditor $editor, NavigationReader $reader): NavigationDetails
    {
        return $reader->details($editor->definition('navigation.show', $this->name));
    }
}
