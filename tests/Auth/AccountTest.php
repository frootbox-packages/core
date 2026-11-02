<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Auth;

use DateTimeImmutable;
use Frootbox\Core\Account\ChangePassword;
use Frootbox\Core\Account\CreateToken;
use Frootbox\Core\Account\ListTokens;
use Frootbox\Core\Account\RevokeToken;
use Frootbox\Core\Account\ShowAccount;
use Frootbox\Core\Account\SignedIn;
use Frootbox\Core\Account\SignIn;
use Frootbox\Core\Account\SignOut;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\EntryStatus;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Auth\AccessToken;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\AuthenticationException;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\Auth\IssuedToken;
use Frootbox\Core\Auth\Passwords;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\UpdateUser;
use Frootbox\Core\User\User;
use Frootbox\Core\User\UserException;
use Frootbox\Core\User\Users;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SignIn::class)]
#[CoversClass(SignOut::class)]
#[CoversClass(SignedIn::class)]
#[CoversClass(ChangePassword::class)]
#[CoversClass(CreateToken::class)]
#[CoversClass(RevokeToken::class)]
#[CoversClass(ListTokens::class)]
#[CoversClass(ShowAccount::class)]
#[CoversClass(AccessTokens::class)]
#[CoversClass(AccessToken::class)]
#[CoversClass(IssuedToken::class)]
#[CoversClass(Passwords::class)]
#[CoversClass(CurrentUser::class)]
#[CoversClass(AuthenticationException::class)]
final class AccountTest extends NotesTestCase
{
    private const string PASSWORD = 'correct horse battery';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->runner()->run(new CreateUser('jane@example.com', 'Jane', self::PASSWORD))->user;
    }

    public function testSignsInAndOut(): void
    {
        $signedIn = $this->signIn();

        self::assertStringStartsWith('fbx_', $signedIn->token);
        self::assertSame(TokenType::Session, $signedIn->session->type);
        self::assertNotNull($signedIn->session->expiresAt);
        self::assertSame('Laptop', $signedIn->session->name);
        self::assertFalse($this->lastEntry()->undoable);

        $authentication = $this->tokens()->authenticate($signedIn->token);
        self::assertNotNull($authentication);
        self::assertSame($this->user->id, $authentication->actor->id);
        self::assertSame('Jane', $authentication->actor->name);

        $context = new Context($authentication->actor, Channel::Api, $authentication->token->id);
        self::assertSame('jane@example.com', $this->runner()->ask(new ShowAccount(), $context)->user->email);
        self::assertTrue($this->runner()->run(new SignOut(), $context));
        self::assertNull($this->tokens()->authenticate($signedIn->token));
    }

    public function testRejectsWrongCredentialsAlike(): void
    {
        $messages = [];

        foreach ([['jane@example.com', 'wrong password'], ['nobody@example.com', self::PASSWORD]] as [$email, $password]) {
            try {
                $this->runner()->run(new SignIn($email, $password), Context::guest());
                self::fail('Signed in with wrong credentials.');
            } catch (AuthenticationException $error) {
                $messages[] = $error->getMessage();
            }

            $entry = $this->lastEntry();
            self::assertSame(EntryStatus::Failed, $entry->status);
            self::assertEquals(['email' => $email, 'password' => '***', 'device' => null, 'remember' => false], $entry->input);
        }

        self::assertSame($messages[0], $messages[1]);
    }

    public function testDisabledUsersCannotSignInAndLoseTheirTokens(): void
    {
        $token = $this->signIn()->token;
        $this->runner()->run(new UpdateUser($this->user->id, 'jane@example.com', 'Jane', false));

        self::assertNull($this->tokens()->authenticate($token));

        $this->expectException(AuthenticationException::class);
        $this->signIn();
    }

    public function testExpiredAndUnknownTokensAreRejected(): void
    {
        $expired = $this->tokens()->issue($this->user->id, TokenType::Api, 'Old', new DateTimeImmutable('-1 minute'));

        self::assertNull($this->tokens()->authenticate($expired->token));
        self::assertNull($this->tokens()->authenticate('fbx_' . str_repeat('0', 64)));
        self::assertNull($this->tokens()->authenticate('something else'));
        self::assertSame(1, $this->tokens()->prune());
    }

    public function testChangingThePasswordSignsOutOtherSessions(): void
    {
        $current = $this->signIn();
        $other = $this->signIn();
        $context = $this->context($current);

        try {
            $this->runner()->run(new ChangePassword('wrong password', 'a new long password'), $context);
            self::fail('The wrong current password was accepted.');
        } catch (InvalidInputException $error) {
            self::assertSame(['/currentPassword' => 'is wrong.'], $error->errors);
        }

        $this->runner()->run(new ChangePassword(self::PASSWORD, 'a new long password'), $context);

        self::assertNotNull($this->tokens()->authenticate($current->token));
        self::assertNull($this->tokens()->authenticate($other->token));
        self::assertTrue(password_verify('a new long password', $this->get(Users::class)->passwordHash($this->user->id) ?? ''));
    }

    public function testApiTokensBelongToTheirUser(): void
    {
        $context = $this->context($this->signIn());
        $issued = $this->runner()->run(new CreateToken('Deploy', 30), $context);

        self::assertSame(TokenType::Api, $issued->accessToken->type);
        self::assertNotNull($this->tokens()->authenticate($issued->token));
        self::assertCount(2, $this->runner()->ask(new ListTokens(), $context));

        $someoneElse = $this->runner()->run(new CreateUser('john@example.com', 'John', self::PASSWORD))->user;
        $foreign = $this->tokens()->issue($someoneElse->id, TokenType::Api, 'John');

        try {
            $this->runner()->run(new RevokeToken($foreign->accessToken->id), $context);
            self::fail('A foreign token was revoked.');
        } catch (UserException) {
        }

        $this->runner()->run(new RevokeToken($issued->accessToken->id), $context);
        self::assertNull($this->tokens()->authenticate($issued->token));
    }

    public function testAccountActionsNeedAUser(): void
    {
        try {
            $this->runner()->run(new CreateToken('Guest'), Context::guest());
            self::fail('A guest created a token.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AuthenticationException::class);
        $this->runner()->run(new CreateToken('System'));
    }

    public function testUpgradesOldPasswordHashes(): void
    {
        $old = password_hash(self::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]);
        $this->connection()->update(Users::TABLE, ['password' => $old], ['id' => $this->user->id]);

        $this->signIn();

        $new = $this->get(Users::class)->passwordHash($this->user->id);
        self::assertNotSame($old, $new);
        self::assertTrue(password_verify(self::PASSWORD, $new ?? ''));
    }

    private function signIn(): SignedIn
    {
        return $this->runner()->run(new SignIn('Jane@Example.com', self::PASSWORD, 'Laptop'), Context::guest());
    }

    private function context(SignedIn $signedIn): Context
    {
        return new Context(Actor::user($this->user->id, $this->user->name), Channel::Api, $signedIn->session->id);
    }

    private function tokens(): AccessTokens
    {
        return $this->get(AccessTokens::class);
    }
}
