<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;

/**
 * @implements Action<NavigationDetails>
 */
#[AsAction('navigation.copy', 'Copies the items of a navigation from one language into another in its draft, replacing the items there')]
final readonly class CopyNavigation implements Action
{
    public function __construct(
        #[Pattern(Definition::NAME_PATTERN)]
        public string $name,
        #[Description('Language to copy from, e.g. "de"')]
        public string $from,
        #[Description('Language to copy to, e.g. "en"')]
        public string $to,
    ) {}

    public function handle(NavigationEditor $editor): NavigationDetails
    {
        return $editor->copy('navigation.copy', $this->name, $this->from, $this->to);
    }
}
