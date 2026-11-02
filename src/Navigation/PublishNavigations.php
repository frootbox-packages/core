<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\ListOf;

/**
 * @implements Action<list<NavigationDetails>>
 */
#[AsAction('navigation.publish', 'Publishes the drafts of one or more navigations together')]
final readonly class PublishNavigations implements Action
{
    /**
     * @param list<string> $names
     */
    public function __construct(
        #[ListOf('string')]
        #[Description('Names of the navigations, e.g. ["main", "footer"]')]
        public array $names,
    ) {}

    /**
     * @return list<NavigationDetails>
     */
    public function handle(NavigationEditor $editor): array
    {
        return $editor->publish('navigation.publish', $this->names);
    }
}
