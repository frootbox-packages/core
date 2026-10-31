<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\Auth\Passwords;
use Frootbox\Core\User\SetPassword;
use Frootbox\Core\User\Users;

/**
 * Changes the own password and signs out all other sessions and tokens.
 *
 * @implements Action<void>
 */
#[AsAction('account.change-password', 'Changes the own password and signs out everywhere else', signedIn: true, undoable: false)]
final readonly class ChangePassword implements Action
{
    public function __construct(
        #[Sensitive]
        #[Length(max: 4096)]
        public string $currentPassword,
        #[Sensitive]
        #[Length(min: SetPassword::MIN_LENGTH, max: SetPassword::MAX_LENGTH)]
        public string $newPassword,
    ) {}

    public function handle(Users $users, Passwords $passwords, AccessTokens $tokens, CurrentUser $current): void
    {
        $id = $current->id();

        if (!$passwords->verify($this->currentPassword, $users->passwordHash($id))) {
            // At the field, so a form shows it there.
            throw new InvalidInputException('account.change-password', ['/currentPassword' => 'is wrong.']);
        }

        $users->update($id, ['password' => $passwords->hash($this->newPassword)]);
        $tokens->revokeAll($id, $current->tokenId());
    }
}
