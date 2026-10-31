<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\User;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Auth\PermissionRules;
use Frootbox\Core\Auth\RolePermissions;
use Frootbox\Core\Database\SensitiveColumns;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Fixtures\Notes\DeleteNote;
use Frootbox\Core\Tests\Fixtures\Notes\RenameNote;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateRole;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\DeleteRole;
use Frootbox\Core\User\DeleteUser;
use Frootbox\Core\User\ListPermissions;
use Frootbox\Core\User\ListRoles;
use Frootbox\Core\User\ListUsers;
use Frootbox\Core\User\RemoveRole;
use Frootbox\Core\User\Role;
use Frootbox\Core\User\RoleAssignments;
use Frootbox\Core\User\Roles;
use Frootbox\Core\User\SetPassword;
use Frootbox\Core\User\SetRoles;
use Frootbox\Core\User\ShowUser;
use Frootbox\Core\User\UpdateRole;
use Frootbox\Core\User\UpdateUser;
use Frootbox\Core\User\User;
use Frootbox\Core\User\UserException;
use Frootbox\Core\User\UserProfile;
use Frootbox\Core\User\Users;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CreateUser::class)]
#[CoversClass(UpdateUser::class)]
#[CoversClass(SetPassword::class)]
#[CoversClass(DeleteUser::class)]
#[CoversClass(SetRoles::class)]
#[CoversClass(ListPermissions::class)]
#[CoversClass(AssignRole::class)]
#[CoversClass(RemoveRole::class)]
#[CoversClass(CreateRole::class)]
#[CoversClass(UpdateRole::class)]
#[CoversClass(DeleteRole::class)]
#[CoversClass(ListUsers::class)]
#[CoversClass(ShowUser::class)]
#[CoversClass(ListRoles::class)]
#[CoversClass(Users::class)]
#[CoversClass(Roles::class)]
#[CoversClass(RoleAssignments::class)]
#[CoversClass(User::class)]
#[CoversClass(Role::class)]
#[CoversClass(UserProfile::class)]
#[CoversClass(UserException::class)]
#[CoversClass(RolePermissions::class)]
#[CoversClass(Gate::class)]
#[CoversClass(SensitiveColumns::class)]
final class UserActionsTest extends NotesTestCase
{
    private const string PASSWORD = 'correct horse battery';

    public function testInstallsStandardRoles(): void
    {
        $roles = $this->runner()->ask(new ListRoles());

        self::assertSame(['admin', 'editor', 'superuser'], array_map(static fn(Role $role): string => $role->name, $roles->all()));
        self::assertSame(['*', '-system.*', '-extension.*'], $roles->all()[0]->permissions);
        self::assertSame([50, 10, 100], array_map(static fn(Role $role): int => $role->level, $roles->all()));
    }

    public function testCreatesAUserWithRolesAndKeepsThePasswordOutOfTheLog(): void
    {
        $profile = $this->createUser('Jane@Example.com', ['admin']);

        self::assertSame('jane@example.com', $profile->user->email);
        self::assertSame([['role' => 'admin', 'scope' => null]], $profile->roles);
        self::assertArrayNotHasKey('password', $profile->user->toArray());

        $hash = $this->get(Users::class)->passwordHash($profile->user->id);
        self::assertNotNull($hash);
        self::assertTrue(password_verify(self::PASSWORD, $hash));

        $logged = $this->connection()->fetchFirstColumn('SELECT input FROM ' . ActionLog::ENTRIES . ' UNION ALL SELECT new_values FROM ' . ActionLog::CHANGES);
        $everything = implode("\n", array_filter($logged, \is_string(...)));
        self::assertStringNotContainsString(self::PASSWORD, $everything);
        self::assertStringNotContainsString($hash, $everything);

        $listed = $this->runner()->ask(new ListUsers());
        self::assertCount(1, $listed);
        self::assertEquals($profile, $this->runner()->ask(new ShowUser($profile->user->id)));
    }

    public function testRejectsADuplicateEmail(): void
    {
        $this->createUser('jane@example.com');

        try {
            $this->createUser('JANE@example.com');
            self::fail('A duplicate email was accepted.');
        } catch (InvalidInputException $error) {
            self::assertSame(['/email' => 'is already in use.'], $error->errors);
        }
    }

    public function testRolesGrantTheirPermissions(): void
    {
        $this->runner()->run(new CreateRole('notes', 'Notes', ['note.*', '-note.delete']));
        $user = $this->createUser('jane@example.com', ['notes'])->user;
        $note = $this->runner()->run(new CreateNote('Hello'))->id;

        $created = $this->runner()->run(new CreateNote('Allowed'), $this->as($user));
        self::assertSame('Allowed', $created->title);

        $this->assertDenied(new DeleteNote($note), $this->as($user));
        $this->assertDenied(new CreateNote('Guest'), Context::guest());

        $this->runner()->run(new UpdateRole('notes', 'Notes', ['note.*'], 0));
        self::assertTrue($this->runner()->run(new DeleteNote($note), $this->as($user)));
    }

    public function testScopedRolesCountOnlyWithinTheirScope(): void
    {
        $user = $this->createUser('jane@example.com')->user;
        $mine = $this->runner()->run(new CreateNote('Mine'))->id;
        $other = $this->runner()->run(new CreateNote('Other'))->id;
        $this->runner()->run(new AssignRole($user->id, 'admin', 'note:' . $mine));

        self::assertSame('Renamed', $this->runner()->run(new RenameNote($mine, 'Renamed'), $this->as($user))->title);
        $this->assertDenied(new RenameNote($other, 'Renamed'), $this->as($user));
        $this->assertDenied(new CreateNote('Scoped'), $this->as($user));
        $this->assertDenied(new DeleteNote($mine), $this->as($user));

        foreach (['note:999', 'page:12', 'nothing'] as $scope) {
            try {
                $this->runner()->run(new AssignRole($user->id, 'editor', $scope));
                self::fail('Roles are given only for something that is there: ' . $scope);
            } catch (InvalidInputException $error) {
                self::assertArrayHasKey('/scope', $error->errors);
            }
        }

        $this->runner()->run(new AssignRole($user->id, 'admin'));
        $this->runner()->run(new CreateNote('Global'), $this->as($user));

        self::assertTrue($this->runner()->run(new RemoveRole($user->id, 'admin')));
        self::assertFalse($this->runner()->run(new RemoveRole($user->id, 'admin')));
        $this->assertDenied(new CreateNote('Removed'), $this->as($user));
    }

    public function testSetsTheGlobalRolesInOneGo(): void
    {
        $user = $this->createUser('jane@example.com', ['editor'])->user;
        $note = $this->runner()->run(new CreateNote('Scope'))->id;
        $this->runner()->run(new AssignRole($user->id, 'editor', 'note:' . $note));

        $profile = $this->runner()->run(new SetRoles($user->id, ['admin', 'admin']));
        self::assertSame([['role' => 'admin', 'scope' => null], ['role' => 'editor', 'scope' => 'note:' . $note]], $profile->roles, 'Scoped roles stay.');

        $profile = $this->runner()->run(new SetRoles($user->id, []));
        self::assertSame([['role' => 'editor', 'scope' => 'note:' . $note]], $profile->roles);
    }

    public function testSettingRolesKeepsTheHierarchy(): void
    {
        $admin = $this->createUser('admin@example.com', ['admin'])->user;
        $jane = $this->createUser('jane@example.com')->user;

        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new SetRoles($jane->id, ['superuser']), $this->as($admin));
    }

    public function testListsThePermissionsARoleCanGrant(): void
    {
        $names = array_column($this->runner()->ask(new ListPermissions()), 'name');

        self::assertContains('page.create', $names);
        self::assertContains('user.set-roles', $names);
        self::assertContains('role.list', $names);
        self::assertNotContains('account.sign-in', $names, 'Everyone may sign in.');
        self::assertNotContains('account.show', $names, 'Every signed-in user may see the own account.');
        self::assertSame($names, array_values(array_unique($names)));
    }

    public function testAssigningARoleTwiceKeepsOne(): void
    {
        $user = $this->createUser('jane@example.com')->user;
        $first = $this->runner()->run(new AssignRole($user->id, 'editor'));
        $second = $this->runner()->run(new AssignRole($user->id, 'editor'));

        self::assertSame($first->id, $second->id);
    }

    public function testUndoingADeleteRestoresTheUserWithoutPassword(): void
    {
        $user = $this->createUser('jane@example.com', ['editor'])->user;
        $this->runner()->run(new DeleteUser($user->id));
        self::assertNull($this->get(Users::class)->fetchById($user->id));

        $this->runner()->run(new UndoAction($this->lastEntry()->id));

        $profile = $this->runner()->ask(new ShowUser($user->id));
        self::assertSame('jane@example.com', $profile->user->email);
        self::assertSame([['role' => 'editor', 'scope' => null]], $profile->roles);
        self::assertNull($this->get(Users::class)->passwordHash($user->id));
    }

    public function testUndoingAnUpdateKeepsThePassword(): void
    {
        $user = $this->createUser('jane@example.com')->user;
        $this->runner()->run(new UpdateUser($user->id, 'jane@example.com', 'Jane Doe', true));
        $this->runner()->run(new UndoAction($this->lastEntry()->id));

        self::assertSame('jane', $this->get(Users::class)->getById($user->id)->name);
        self::assertTrue(password_verify(self::PASSWORD, $this->get(Users::class)->passwordHash($user->id) ?? ''));
    }

    public function testChangingAPasswordCannotBeUndone(): void
    {
        $user = $this->createUser('jane@example.com')->user;
        $this->runner()->run(new SetPassword($user->id, 'another long password'));

        self::assertFalse($this->lastEntry()->undoable);
        self::assertTrue(password_verify('another long password', $this->get(Users::class)->passwordHash($user->id) ?? ''));
    }

    public function testProtectsTheOwnAccountAndTheAdminRole(): void
    {
        $admin = $this->createUser('admin@example.com', ['admin'])->user;

        foreach ([new DeleteUser($admin->id), new UpdateUser($admin->id, 'admin@example.com', 'Admin', false), new DeleteRole('admin')] as $action) {
            try {
                $this->runner()->run($action, $this->as($admin));
                self::fail(\sprintf('%s was allowed.', $action::class));
            } catch (UserException) {
                self::assertSame('failed', $this->lastEntry()->status->value);
            }
        }
    }

    public function testDeletingARoleTakesItAway(): void
    {
        $user = $this->createUser('jane@example.com', ['editor'])->user;

        self::assertTrue($this->runner()->run(new DeleteRole('editor')));
        self::assertSame([], $this->get(RoleAssignments::class)->rolesOf($user->id));
    }

    public function testRejectsInvalidPermissionRules(): void
    {
        try {
            $this->runner()->run(new CreateRole('broken', 'Broken', ['page.*', 'Page.create']));
            self::fail('An invalid rule was accepted.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/permissions/1', $error->errors);
        }

        self::assertTrue(PermissionRules::isValid('page.*'));
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = []): UserProfile
    {
        return $this->runner()->run(new CreateUser($email, explode('@', $email)[0], self::PASSWORD, $roles));
    }

    private function as(User $user): Context
    {
        return new Context(Actor::user($user->id, $user->name), Channel::Api);
    }

    /**
     * @param Action<mixed> $action
     */
    private function assertDenied(Action $action, Context $context): void
    {
        try {
            $this->runner()->run($action, $context);
            self::fail(\sprintf('%s was allowed.', $action::class));
        } catch (AccessDeniedException) {
            self::assertSame('denied', $this->lastEntry()->status->value);
        }
    }
}
