<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

/**
 * The answer of account.request-email-change (decision 0341).
 */
final readonly class EmailChange
{
    public function __construct(
        /** The new email */
        public string $email,
        /** A link to confirm it is on its way; false: it holds already */
        public bool $pending,
    ) {}
}
