<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\I18n\Labels;

/**
 * The layouts a page can have, for choosing one when creating a page.
 *
 * @implements Query<list<LayoutSummary>>
 */
#[AsQuery('layout.list', 'Lists the layouts a page can have, with title, description and variants', permission: 'page.tree')]
final readonly class ListLayouts implements Query, Scoped
{
    /**
     * A role within a branch is enough to create pages there.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [];
    }

    /**
     * @return list<LayoutSummary>
     */
    public function handle(Components $components, Labels $labels): array
    {
        $layouts = array_map(static fn(Component $layout): LayoutSummary => LayoutSummary::of($labels->component($layout), offered: $components->offers(ComponentKind::Layout, $layout->name)), array_values($components->all(ComponentKind::Layout)));
        usort($layouts, static fn(LayoutSummary $a, LayoutSummary $b): int => [$a->title, $a->name] <=> [$b->title, $b->name]);

        return $layouts;
    }
}
