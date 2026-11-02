<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Database\Ulid;

/**
 * @implements Action<PageDetails>
 */
#[AsAction('page.lock', 'Locks a published page for visitors right away (503) without taking it offline; the draft stays as it is')]
final readonly class LockPage implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Also lock all pages below it')]
        public bool $branch = false,
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
        return $editor->lock('page.lock', $this->id, $this->branch ? Reach::Branch : Reach::Page);
    }
}
