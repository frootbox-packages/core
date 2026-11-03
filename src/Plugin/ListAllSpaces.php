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
 * @implements Query<list<SpaceScope>>
 */
#[AsQuery('space.all', 'The spaces of all plugins with the scope for giving a role only there, e.g. "space:frootbox/jobs/job:default"; see user.assign-role', signedIn: true)]
final readonly class ListAllSpaces implements Query
{
    /**
     * @return list<SpaceScope>
     */
    public function handle(Plugins $plugins, PluginSpaces $spaces, Translator $translator): array
    {
        $all = [];

        foreach ($plugins->all() as $plugin) {
            $label = $translator->get($plugin->label());

            foreach ($spaces->all($plugin->type()) as $space) {
                $all[] = new SpaceScope(
                    SpaceScopes::KIND . ':' . SpaceScopes::id($plugin->type(), $space->id),
                    $plugin->type(),
                    $space->id,
                    // "Jobs" for the default space, "Jobs · Ausbildung" for others and a renamed default one.
                    $space->title === $label ? $label : $label . ' · ' . $space->title,
                );
            }
        }

        usort($all, static fn(SpaceScope $a, SpaceScope $b): int => [$a->title, $a->scope] <=> [$b->title, $b->scope]);

        return $all;
    }
}
