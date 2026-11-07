<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

/**
 * The answer of account.password-reset-show (decision 0325).
 */
final readonly class PasswordResetState
{
    public function __construct(
        /** The website mails links: APP_SECRET, app.url and a way for mails */
        public bool $available,
        /** The token works */
        public bool $valid,
        /** The email of the account, for a password manager */
        public ?string $email = null,
    ) {}
}
