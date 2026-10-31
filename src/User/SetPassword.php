<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\Auth\Hierarchy;
use Frootbox\Core\Auth\Passwords;

/**
 * Sets a new password and signs the user out everywhere else.
 *
 * @implements Action<void>
 */
#[AsAction('user.set-password', 'Sets a new password for a user and signs them out on all devices', undoable: false)]
final readonly class SetPassword implements Action
{
    public const int MIN_LENGTH = 10;

    public const int MAX_LENGTH = 256;

    public function __construct(
        public int $id,
        #[Sensitive]
        #[Length(min: self::MIN_LENGTH, max: self::MAX_LENGTH)]
        public string $password,
    ) {}

    public function handle(Users $users, Passwords $passwords, AccessTokens $tokens, CurrentUser $current, Hierarchy $hierarchy): void
    {
        $hierarchy->assertMayManageUser($this->id);
        $users->update($this->id, ['password' => $passwords->hash($this->password)]);
        $tokens->revokeAll($this->id, $current->is($this->id) ? $current->tokenId() : null);
    }
}
