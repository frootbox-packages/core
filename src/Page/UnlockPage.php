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
 * @implements Action<PageDetails>
 */
#[AsAction('page.unlock', 'Lifts the lock of a page set with page.lock; a lock of a page above stays')]
final readonly class UnlockPage implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(PageEditor $editor): PageDetails
    {
        return $editor->lock('page.unlock', $this->id, Reach::None);
    }
}
