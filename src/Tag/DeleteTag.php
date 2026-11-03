<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<null>
 */
#[AsAction('tag.delete', 'Deletes a tag; the objects with it stay')]
final readonly class DeleteTag implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(TagEditor $editor): null
    {
        $editor->delete('tag.delete', $this->id);

        return null;
    }
}
