<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\ActionException;

final class UserException extends ActionException
{
    public static function notYourself(string $what): self
    {
        return new self(\sprintf('You cannot %s yourself.', $what));
    }

    public static function protectedRole(string $name): self
    {
        return new self(\sprintf('The role "%s" cannot be deleted.', $name));
    }

    public static function tokenNotFound(int $id): self
    {
        return new self(\sprintf('You have no token #%d.', $id));
    }
}
