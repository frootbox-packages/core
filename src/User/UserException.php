<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Support\Message;

final class UserException extends ActionException
{
    public static function notYourself(string $what): self
    {
        return new self(match ($what) {
            'delete' => 'You cannot delete yourself.',
            'disable' => 'You cannot disable yourself.',
            default => new Message('You cannot {what} yourself.', ['what' => $what]),
        });
    }

    public static function protectedRole(string $name): self
    {
        return new self(new Message('The role "{role}" cannot be deleted.', ['role' => $name]));
    }

    public static function tokenNotFound(int $id): self
    {
        return new self(new Message('You have no token #{id}.', ['id' => $id]));
    }
}
