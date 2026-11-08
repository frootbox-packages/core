<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\Passwords;
use Frootbox\Core\Auth\SignInThrottle;
use Frootbox\Core\User\SetPassword;
use Frootbox\Core\User\User;
use Frootbox\Core\User\Users;

/**
 * Sets the first password with the link of an invitation (decision 0342).
 * The backend signs the person in afterwards.
 *
 * @implements Action<User>
 */
#[AsAction('account.accept-invitation', 'Sets the first password of an account with the link of an invitation', public: true, undoable: false)]
final readonly class AcceptInvitation implements Action
{
    public function __construct(
        #[Sensitive]
        #[Length(max: 200)]
        public string $token,
        #[Sensitive]
        #[Length(min: SetPassword::MIN_LENGTH, max: SetPassword::MAX_LENGTH)]
        public string $password,
    ) {}

    public function handle(PasswordResets $links, Users $users, Passwords $passwords, SignInThrottle $throttle, Journal $journal): User
    {
        $user = $links->invited($this->token);

        if (null === $user || null === $user->email) {
            throw new InvalidInputException('account.accept-invitation', ['/token' => 'is invalid or expired.']);
        }

        $users->update($user->id, ['password' => $passwords->hash($this->password)]);
        $email = $user->email;
        $journal->trusted(fn() => $throttle->clear($email));

        return $user;
    }
}
