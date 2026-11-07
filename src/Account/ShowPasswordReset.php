<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;

/**
 * Whether the website offers a link for a forgotten password and whether a
 * link still works (decision 0325).
 *
 * @implements Query<PasswordResetState>
 */
#[AsQuery('account.password-reset-show', 'Tells whether the website mails links for a forgotten password and whether a link still works', public: true)]
final readonly class ShowPasswordReset implements Query
{
    public function __construct(
        #[Description('The token from the link, empty to only ask whether the website offers links')]
        #[Sensitive]
        #[Length(max: 200)]
        public ?string $token = null,
    ) {}

    public function handle(PasswordResets $resets): PasswordResetState
    {
        $available = $resets->available();
        $user = $available && null !== $this->token && '' !== $this->token ? $resets->user($this->token) : null;

        return new PasswordResetState($available, null !== $user, $user?->email);
    }
}
