<?php

/*
 * SPDX-License-Identifier: MIT
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

    /**
     * A service account signs in only with tokens (decision 0285).
     */
    public static function serviceAccount(string $what): self
    {
        return new self(match ($what) {
            'has no password' => 'A service account has no password, it uses API tokens.',
            'own tokens' => 'A service account gets tokens only through user.create-token.',
            'invite' => 'A service account gets no invitation, it uses API tokens.',
            default => new Message('A service account {what}.', ['what' => $what]),
        });
    }

    public static function notAServiceAccount(int $id): self
    {
        return new self(new Message('User #{id} is no service account; people create their own tokens.', ['id' => $id]));
    }

    public static function tokenNotFound(int $id): self
    {
        return new self(new Message('You have no token #{id}.', ['id' => $id]));
    }
}
