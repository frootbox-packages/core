<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\I18n\Translator;

/**
 * Everything a page can show, for choosing it in the settings of a page
 * (decision 0232): each plugin with its spaces, sorted by name.
 *
 * @implements Query<list<SpaceList>>
 */
#[AsQuery('plugin.list', 'The plugins a page can show, each with its spaces and the page that shows each', permission: 'plugin.assign')]
final readonly class ListPlugins implements Query
{
    /**
     * @return list<SpaceList>
     */
    public function handle(Plugins $plugins, PluginSpaces $spaces, Translator $translator): array
    {
        $lists = array_map(static fn(Plugin $plugin): SpaceList => new SpaceList($plugin->type(), $translator->get($plugin->label()), $plugin->spaces(), $spaces->all($plugin->type()), $plugin->details()), array_values($plugins->all()));
        usort($lists, static fn(SpaceList $a, SpaceList $b): int => $a->label <=> $b->label);

        return $lists;
    }
}
