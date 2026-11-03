<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<null>
 */
#[AsAction('category-group.delete', 'Deletes a group of categories created in the backend; it must have no categories')]
final readonly class DeleteGroup implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(GroupEditor $editor): null
    {
        $editor->delete('category-group.delete', $this->id);

        return null;
    }
}
