<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\User\User;
use Frootbox\Core\User\Users;

/**
 * Sets the new email with the link from the mail (decision 0341). It may
 * be opened on another device, without signing in there.
 *
 * @implements Action<User>
 */
#[AsAction('account.confirm-email-change', 'Sets the new email of an account with the link from the mail', public: true, undoable: false)]
final readonly class ConfirmEmailChange implements Action
{
    public function __construct(
        #[Sensitive]
        #[Length(max: 600)]
        public string $token,
    ) {}

    public function handle(EmailChanges $changes, Users $users): User
    {
        $change = $changes->change($this->token);

        if (null === $change) {
            throw new InvalidInputException('account.confirm-email-change', ['/token' => 'is invalid or expired.']);
        }

        [$user, $email] = $change;
        $other = $users->findByEmail($email);

        if (null !== $other && $other->id !== $user->id) {
            throw new InvalidInputException('account.confirm-email-change', ['/token' => 'is for an email that is in use by now.']);
        }

        return $users->update($user->id, ['email' => $email]);
    }
}
