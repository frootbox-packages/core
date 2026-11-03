<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Url\AddressSync;
use Frootbox\Database\Ulid;

/**
 * @implements Action<null>
 */
#[AsAction('category.delete', 'Deletes a category with all below it; the objects in it stay')]
final readonly class DeleteCategory implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(CategoryEditor $editor, AddressSync $addresses): null
    {
        $editor->delete('category.delete', $this->id);
        // The pages of the categories follow (decision 0243).
        $addresses->sync();

        return null;
    }
}
