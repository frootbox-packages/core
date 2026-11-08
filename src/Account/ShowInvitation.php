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
 * Name and email of the person an invitation is for, null if the link
 * does not work any more (decision 0342).
 *
 * @implements Query<array{name: string, email: string}|null>
 */
#[AsQuery('account.invitation-show', 'Returns name and email of the person an invitation link is for, null if it does not work any more', public: true)]
final readonly class ShowInvitation implements Query
{
    public function __construct(
        #[Sensitive]
        #[Length(max: 200)]
        public string $token,
    ) {}

    /**
     * @return array{name: string, email: string}|null
     */
    public function handle(PasswordResets $links): ?array
    {
        $user = $links->invited($this->token);

        return null === $user || null === $user->email ? null : ['name' => $user->name, 'email' => $user->email];
    }
}
