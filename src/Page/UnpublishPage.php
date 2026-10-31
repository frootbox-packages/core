<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Database\Ulid;

/**
 * @implements Action<PageDetails>
 */
#[AsAction('page.unpublish', 'Takes a page and its subpages offline; they stay as drafts and can be published again')]
final readonly class UnpublishPage implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(PageEditor $editor): PageDetails
    {
        return $editor->unpublish('page.unpublish', $this->id);
    }
}
