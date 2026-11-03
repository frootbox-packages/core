<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Length;

/**
 * @implements Action<Space>
 */
#[AsAction('space.update', 'Renames a space of a plugin')]
final readonly class UpdateSpace implements Action
{
    public function __construct(
        public string $id,
        #[Length(1, 100)]
        public string $title,
    ) {}

    public function handle(SpaceEditor $editor): Space
    {
        return $editor->rename('space.update', $this->id, $this->title);
    }
}
