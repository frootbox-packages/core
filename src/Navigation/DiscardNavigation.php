<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Pattern;

/**
 * @implements Action<NavigationDetails>
 */
#[AsAction('navigation.discard', 'Throws away the draft of a navigation')]
final readonly class DiscardNavigation implements Action
{
    public function __construct(
        #[Pattern(Definition::NAME_PATTERN)]
        public string $name,
    ) {}

    public function handle(NavigationEditor $editor): NavigationDetails
    {
        return $editor->discard('navigation.discard', $this->name);
    }
}
