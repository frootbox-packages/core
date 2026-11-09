<?php

/*
 * SPDX-License-Identifier: MIT
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
 * Switches the password of a page off (decision 0358). Not undoable: the
 * hash is not recorded.
 *
 * @implements Action<PageDetails>
 */
#[AsAction('page.unprotect', 'Switches the password of a page set with page.protect off; a password of a page above stays', undoable: false)]
final readonly class UnprotectPage implements Action, Scoped
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

    public function handle(PageReader $reader, PageProtections $protections, PagePasswords $passwords): PageDetails
    {
        $reader->details($this->id);
        $current = $protections->of($this->id);

        if (null !== $current) {
            $protections->delete($current->id);
        }

        $passwords->clear();

        return $reader->details($this->id);
    }
}
