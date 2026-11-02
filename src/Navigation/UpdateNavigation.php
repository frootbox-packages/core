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
#[AsAction('navigation.update', 'Replaces the items of a navigation in one or more languages in its draft')]
final readonly class UpdateNavigation implements Action
{
    /**
     * @param array<mixed> $items
     */
    public function __construct(
        #[Pattern(Definition::NAME_PATTERN)]
        #[Description('Name of the navigation as declared by the theme, e.g. "main"')]
        public string $name,
        #[Description('Items by language; a language set to null is removed, languages left out stay: {"de": [{"type": "target", "target": "page:01J…"}, {"type": "phone", "phone": "+49 40 123456"}]}. Types: target, url, anchor, phone, email, heading, pages')]
        public array $items,
    ) {}

    public function handle(NavigationEditor $editor): NavigationDetails
    {
        return $editor->update('navigation.update', $this->name, $this->items);
    }
}
