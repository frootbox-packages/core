<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;

/**
 * The new email of a link to confirm it, null if the link does not work
 * (decision 0341).
 *
 * @implements Query<?string>
 */
#[AsQuery('account.email-change-show', 'Returns the new email a confirmation link is for, null if it does not work any more', public: true)]
final readonly class ShowEmailChange implements Query
{
    public function __construct(
        #[Sensitive]
        #[Length(max: 600)]
        public string $token,
    ) {}

    public function handle(EmailChanges $changes): ?string
    {
        return $changes->change($this->token)[1] ?? null;
    }
}
