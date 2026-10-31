<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * Takes the page offline right away, without a draft (decision 0101).
 * Its versions stay and page.restore brings it back.
 *
 * @implements Action<bool>
 */
#[AsAction('page.delete', 'Deletes a page without subpages, published state and draft')]
final readonly class DeletePage implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(PageEditor $editor): bool
    {
        $editor->delete('page.delete', $this->id);

        return true;
    }
}
