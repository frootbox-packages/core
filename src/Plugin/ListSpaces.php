<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Support\Message;

/**
 * @implements Query<SpaceList>
 */
#[AsQuery('space.list', 'The spaces of a plugin with the page that shows each, the default one first', signedIn: true)]
final readonly class ListSpaces implements Query
{
    public function __construct(
        #[Description('The type of the plugin, e.g. "frootbox/jobs/job"')]
        public string $type,
        #[Description('Only the spaces where the user holds this permission, e.g. "frootbox/jobs/job.list"; left out for all')]
        #[Length(max: 100)]
        public ?string $permission = null,
    ) {}

    public function handle(Plugins $plugins, PluginSpaces $spaces, SpaceRights $rights, Translator $translator): SpaceList
    {
        $plugin = $plugins->get($this->type) ?? throw new InvalidInputException('space.list', ['/type' => new Message('There is no plugin "{type}".', ['type' => $this->type])]);
        $items = $spaces->all($this->type);

        // Roles within spaces (decision 0245): only the spaces the user may work in.
        $within = null === $this->permission ? null : $rights->within($this->type, $this->permission);

        if (null !== $within) {
            $items = array_values(array_filter($items, static fn(SpaceSummary $space): bool => \in_array($space->id, $within, true)));
        }

        return new SpaceList($this->type, $translator->get($plugin->label()), $plugin->spaces(), $items, $plugin->details(), $plugin->categories());
    }
}
