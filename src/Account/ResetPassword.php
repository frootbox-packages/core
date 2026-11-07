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
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\Passwords;
use Frootbox\Core\Auth\SignInThrottle;
use Frootbox\Core\User\SetPassword;
use Frootbox\Core\User\User;
use Frootbox\Core\User\Users;

/**
 * Sets a new password with a link from the mail (decision 0326), signs the
 * person out everywhere and forgets failed sign-ins, so someone who locked
 * themselves out can sign in right away.
 *
 * @implements Action<User>
 */
#[AsAction('account.reset-password', 'Sets a new password with the link from the mail and signs out everywhere', public: true, undoable: false)]
final readonly class ResetPassword implements Action
{
    public function __construct(
        #[Sensitive]
        #[Length(max: 200)]
        public string $token,
        #[Sensitive]
        #[Length(min: SetPassword::MIN_LENGTH, max: SetPassword::MAX_LENGTH)]
        public string $password,
    ) {}

    public function handle(PasswordResets $resets, Users $users, Passwords $passwords, AccessTokens $tokens, SignInThrottle $throttle, Journal $journal): User
    {
        $user = $resets->user($this->token);

        if (null === $user || null === $user->email) {
            throw new InvalidInputException('account.reset-password', ['/token' => 'is invalid or expired.']);
        }

        $users->update($user->id, ['password' => $passwords->hash($this->password)]);
        $tokens->revokeAll($user->id);
        $email = $user->email;
        $journal->trusted(fn() => $throttle->clear($email));

        return $user;
    }
}
