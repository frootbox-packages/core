<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\ActionException;

final class AuthenticationException extends ActionException
{
    /**
     * Deliberately the same for an unknown email, a wrong password and a
     * disabled account.
     */
    public static function wrongCredentials(): self
    {
        return new self('The email or password is wrong.');
    }

    public static function wrongPassword(): self
    {
        return new self('The current password is wrong.');
    }

    public static function userRequired(): self
    {
        return new self('Only a signed-in user can do this.');
    }
}
