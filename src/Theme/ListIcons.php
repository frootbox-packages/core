<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\I18n\Labels;

/**
 * The symbols of the themes for choosing one for an icon field
 * (decision 0179), sorted by label.
 *
 * @implements Query<list<IconSummary>>
 */
#[AsQuery('icon.list', 'Lists the symbols of the theme an icon field can show, with name, label and SVG', permission: 'page.tree')]
final readonly class ListIcons implements Query, Scoped
{
    /**
     * A role within a branch is enough to edit pages there.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [];
    }

    /**
     * @return list<IconSummary>
     */
    public function handle(Icons $icons, Labels $labels): array
    {
        $list = array_map(
            static fn(string $name): IconSummary => new IconSummary($name, $labels->text(Icons::label($name), $icons->themeOf($name)), (string) $icons->svg($name)),
            $icons->names(),
        );
        usort($list, static fn(IconSummary $a, IconSummary $b): int => [mb_strtolower($a->label), $a->name] <=> [mb_strtolower($b->label), $b->name]);

        return $list;
    }
}
