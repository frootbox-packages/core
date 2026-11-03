<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;

/**
 * @implements Action<Space>
 */
#[AsAction('space.update', 'Renames a space of a plugin, also the default one with its type')]
final readonly class UpdateSpace implements Action
{
    public function __construct(
        #[Description('The id of the space, "default" for the default one')]
        public string $id,
        #[Length(1, 100)]
        public string $title,
        #[Description('The type of the plugin, needed for the default space, e.g. "frootbox/collections/entry"')]
        public ?string $type = null,
    ) {}

    public function handle(SpaceEditor $editor): Space
    {
        return $editor->rename('space.update', $this->id, $this->title, $this->type);
    }
}
