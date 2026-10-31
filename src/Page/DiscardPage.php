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
 * @implements Action<PageDetails|null>
 */
#[AsAction('page.discard', 'Throws away the draft of a page; a page that was never published is gone then')]
final readonly class DiscardPage implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(PageEditor $editor): ?PageDetails
    {
        return $editor->discard('page.discard', $this->id);
    }
}
