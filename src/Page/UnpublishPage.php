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
#[AsAction('page.unpublish', 'Takes a page and its subpages offline; they stay as drafts and can be published again')]
final readonly class UnpublishPage implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(PageEditor $editor): PageDetails
    {
        return $editor->unpublish('page.unpublish', $this->id);
    }
}
