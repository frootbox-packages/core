<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Schema\Email;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\AuthenticationException;
use Frootbox\Core\Auth\Passwords;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\User\Users;

/**
 * Checks email and password and starts a session. Failed attempts stay in
 * the action log with the email.
 *
 * @implements Action<SignedIn>
 */
#[AsAction('account.sign-in', 'Signs in with email and password and starts a session', public: true, undoable: false)]
final readonly class SignIn implements Action
{
    public function __construct(
        #[Email]
        #[Length(max: 255)]
        public string $email,
        #[Sensitive]
        #[Length(min: 1, max: 4096)]
        public string $password,
        #[Length(max: 255)]
        public ?string $device = null,
    ) {}

    public function handle(Users $users, Passwords $passwords, AccessTokens $tokens, Connection $connection, Journal $journal): SignedIn
    {
        $user = $users->findByEmail($this->email);
        $hash = null === $user ? null : $users->passwordHash($user->id);

        if (!$passwords->verify($this->password, $hash) || null === $user || null === $hash || !$user->active) {
            throw AuthenticationException::wrongCredentials();
        }

        if ($passwords->needsRehash($hash)) {
            // Bookkeeping like the session itself, not recorded for undoing.
            $journal->trusted(fn(): int|string => $connection->update(Users::TABLE, ['password' => $passwords->hash($this->password)], ['id' => $user->id]));
        }

        $issued = $tokens->issue($user->id, TokenType::Session, $this->device);

        return new SignedIn($issued->token, $issued->accessToken, $user);
    }
}
