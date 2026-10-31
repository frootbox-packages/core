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
 * Takes the page offline right away, without a draft (decision 0101).
 * Its versions stay and page.restore brings it back.
 *
 * @implements Action<bool>
 */
#[AsAction('page.delete', 'Deletes a page without subpages, published state and draft')]
final readonly class DeletePage implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    /**
     * Below the top of a branch: the top itself is deleted only from above (decision 0118).
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id, strict: true)];
    }

    public function handle(PageEditor $editor): bool
    {
        $editor->delete('page.delete', $this->id);

        return true;
    }
}
