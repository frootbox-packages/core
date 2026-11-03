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
#[AsAction('space.create', 'Adds a space to a plugin, e.g. the jobs "Ausbildung" next to all other jobs, for its own page')]
final readonly class CreateSpace implements Action
{
    public function __construct(
        #[Description('The type of the plugin, e.g. "frootbox/jobs/job"')]
        public string $type,
        #[Length(1, 100)]
        #[Description('The name editors see, e.g. "Ausbildung"')]
        public string $title,
    ) {}

    public function handle(SpaceEditor $editor): Space
    {
        return $editor->create('space.create', $this->type, $this->title);
    }
}
