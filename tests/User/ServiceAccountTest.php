<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\User;

use Frootbox\Core\Account\CreateToken;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Console\ActionLogCommand;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateServiceToken;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\SetPassword;
use Frootbox\Core\User\UpdateUser;
use Frootbox\Core\User\User;
use Frootbox\Core\User\UserException;
use Frootbox\Core\User\UserKind;
use Frootbox\Core\User\Users;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CreateServiceToken::class)]
#[CoversClass(CreateUser::class)]
#[CoversClass(UpdateUser::class)]
#[CoversClass(SetPassword::class)]
#[CoversClass(CreateToken::class)]
#[CoversClass(UserKind::class)]
#[CoversClass(UserException::class)]
#[CoversClass(ActionLogCommand::class)]
final class ServiceAccountTest extends NotesTestCase
{
    public function testCreatesAServiceAccountWithoutEmailAndPassword(): void
    {
        $service = $this->service();

        self::assertSame(UserKind::Service, $service->kind);
        self::assertNull($service->email);
        self::assertNull($this->get(Users::class)->passwordHash($service->id));
        self::assertNull($this->service('Monitoring')->email, 'Several service accounts share the empty email.');
    }

    public function testAServiceAccountTakesNoEmailOrPasswordAndAPersonNeedsAnEmail(): void
    {
        $this->assertInvalid(new CreateUser('robot@example.com', 'Robot', 'kaffeebohne42', kind: UserKind::Service), [
            '/email' => 'must be empty for a service account.',
            '/password' => 'must be empty for a service account.',
        ]);
        $this->assertInvalid(new CreateUser(null, 'Jane'), ['/email' => 'is required.']);

        $service = $this->service();
        $this->assertInvalid(new UpdateUser($service->id, 'robot@example.com', 'Robot', true), ['/email' => 'must be empty for a service account.']);

        $renamed = $this->runner()->run(new UpdateUser($service->id, null, 'Status robot', true));
        self::assertSame('Status robot', $renamed->name);
        self::assertNull($renamed->email);
    }

    public function testIssuesATokenThatActsWithTheRolesOfTheServiceAccount(): void
    {
        $service = $this->service(roles: ['editor']);

        $issued = $this->runner()->run(new CreateServiceToken($service->id, 'Status', days: 30));
        $authentication = $this->get(AccessTokens::class)->authenticate($issued->token);

        self::assertNotNull($authentication);
        self::assertSame($service->id, $authentication->actor->id);
        self::assertSame(TokenType::Api, $issued->accessToken->type);
        self::assertNotNull($issued->accessToken->expiresAt);
        self::assertFalse($this->lastEntry()->undoable);
        $input = $this->connection()->fetchOne('SELECT input FROM ' . ActionLog::ENTRIES . ' ORDER BY id DESC');
        self::assertIsString($input);
        self::assertStringNotContainsString($issued->token, $input);

        $this->runner()->run(new UpdateUser($service->id, null, 'Robot', false));
        self::assertNull($this->get(AccessTokens::class)->authenticate($issued->token), 'A disabled service account has no working token.');
    }

    public function testGivesTokensOnlyToServiceAccountsAndOnlyToThoseAllowed(): void
    {
        $person = $this->runner()->run(new CreateUser('jane@example.com', 'Jane', 'kaffeebohne42', ['editor']))->user;
        $service = $this->service();

        try {
            $this->runner()->run(new CreateServiceToken($person->id, 'As Jane'));
            self::fail('A token for a person was issued.');
        } catch (UserException $error) {
            self::assertStringContainsString('no service account', $error->getMessage());
        }

        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new CreateServiceToken($service->id, 'Status'), $this->as($person));
    }

    public function testAServiceAccountHasNoPasswordAndMakesNoTokensOfItsOwn(): void
    {
        $service = $this->service();

        foreach ([new SetPassword($service->id, 'kaffeebohne42'), new CreateToken('More')] as $action) {
            try {
                $this->runner()->run($action, $action instanceof CreateToken ? $this->as($service) : Context::system());
                self::fail(\sprintf('%s was allowed.', $action::class));
            } catch (UserException $error) {
                self::assertStringContainsString('service account', $error->getMessage());
            }
        }
    }

    public function testTheLogShowsWhenAServiceAccountActed(): void
    {
        $service = $this->service(roles: ['admin']);
        $this->runner()->run(new CreateNote('From the robot'), $this->as($service));

        $tester = new CommandTester(new ActionLogCommand($this->get(ActionLog::class), $this->get(Users::class)));
        $tester->execute([]);

        self::assertStringContainsString('Robot (service account)', $tester->getDisplay());
    }

    /**
     * @param list<string> $roles
     */
    private function service(string $name = 'Robot', array $roles = []): User
    {
        return $this->runner()->run(new CreateUser(null, $name, roles: $roles, kind: UserKind::Service))->user;
    }

    private function as(User $user): Context
    {
        return new Context(Actor::user($user->id, $user->name), Channel::Api);
    }

    /**
     * @param \Frootbox\Core\Action\Action<mixed> $action
     * @param array<string, string>               $errors
     */
    private function assertInvalid(\Frootbox\Core\Action\Action $action, array $errors): void
    {
        try {
            $this->runner()->run($action);
            self::fail(\sprintf('%s was accepted.', $action::class));
        } catch (InvalidInputException $error) {
            self::assertSame($errors, $error->errors);
        }
    }
}
