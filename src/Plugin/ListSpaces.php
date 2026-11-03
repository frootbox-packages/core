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
    ) {}

    public function handle(Plugins $plugins, PluginSpaces $spaces, Translator $translator): SpaceList
    {
        $plugin = $plugins->get($this->type) ?? throw new InvalidInputException('space.list', ['/type' => new Message('There is no plugin "{type}".', ['type' => $this->type])]);

        return new SpaceList($this->type, $translator->get($plugin->label()), $plugin->spaces(), $spaces->all($this->type), $plugin->details());
    }
}
