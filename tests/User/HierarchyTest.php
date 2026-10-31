<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\User;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Action\Undo\Undoer;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Hierarchy;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateRole;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\DeleteRole;
use Frootbox\Core\User\DeleteUser;
use Frootbox\Core\User\RemoveRole;
use Frootbox\Core\User\SetPassword;
use Frootbox\Core\User\UpdateRole;
use Frootbox\Core\User\UpdateUser;
use Frootbox\Core\User\User;
use Frootbox\Core\User\UserException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Hierarchy::class)]
#[CoversClass(Undoer::class)]
final class HierarchyTest extends NotesTestCase
{
    private User $superuser;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superuser = $this->user('me@example.com', ['superuser']);
        $this->admin = $this->user('admin@agency.example', ['admin']);
    }

    public function testAdminsCannotTouchASuperuser(): void
    {
        $actions = [
            new UpdateUser($this->superuser->id, 'me@example.com', 'Changed', true),
            new SetPassword($this->superuser->id, 'a takeover password'),
            new DeleteUser($this->superuser->id),
            new RemoveRole($this->superuser->id, 'superuser'),
            new AssignRole($this->admin->id, 'superuser'),
        ];

        foreach ($actions as $action) {
            $this->assertDenied($action, $this->admin);
        }

        self::assertSame('me', $this->get(\Frootbox\Core\User\Users::class)->getById($this->superuser->id)->name);
    }

    public function testAdminsManageOtherAdminsAndEditors(): void
    {
        $colleague = $this->user('colleague@agency.example', ['editor']);

        $this->runner()->run(new AssignRole($colleague->id, 'admin'), $this->as($this->admin));
        $this->runner()->run(new UpdateUser($colleague->id, 'colleague@agency.example', 'Colleague', true), $this->as($this->admin));
        self::assertTrue($this->runner()->run(new DeleteUser($colleague->id), $this->as($this->admin)));
    }

    public function testSuperusersManageAdmins(): void
    {
        $this->runner()->run(new SetPassword($this->admin->id, 'reset by superuser'), $this->as($this->superuser));
        $this->runner()->run(new UpdateRole('admin', 'Agency admin', ['*', '-system.*', '-extension.*', '-role.*'], 50), $this->as($this->superuser));

        $this->expectException(UserException::class);
        $this->runner()->run(new DeleteRole('superuser'), $this->as($this->superuser));
    }

    public function testRolesAreChangedOnlyBelowTheOwnLevel(): void
    {
        $this->assertDenied(new UpdateRole('admin', 'Admin', ['*'], 50), $this->admin);
        $this->assertDenied(new CreateRole('agency', 'Agency', ['note.*'], 50), $this->admin);
        $this->assertDenied(new UpdateRole('editor', 'Editor', ['page.*'], 60), $this->admin);

        $role = $this->runner()->run(new CreateRole('agency', 'Agency', ['note.*'], 40), $this->as($this->admin));
        self::assertSame(40, $role->level);
        $this->runner()->run(new DeleteRole('agency'), $this->as($this->admin));
    }

    public function testRolesGrantOnlyPermissionsOneHas(): void
    {
        $this->runner()->run(new CreateRole('manager', 'Manager', ['role.*', 'note.create'], 30));
        $manager = $this->user('manager@agency.example', ['manager']);

        try {
            $this->runner()->run(new CreateRole('writer', 'Writer', ['note.*'], 20), $this->as($manager));
            self::fail('A role with more permissions than its creator was accepted.');
        } catch (InvalidInputException $error) {
            self::assertStringContainsString('note.delete', $error->errors['/permissions'] ?? '');
        }

        $this->runner()->run(new CreateRole('writer', 'Writer', ['note.create'], 20), $this->as($manager));
    }

    public function testNobodyUndoesTheWorkOfAHigherRank(): void
    {
        $this->runner()->run(new CreateNote('By the superuser'), $this->as($this->superuser));
        $bySuperuser = $this->lastEntry()->id;
        $this->runner()->run(new CreateNote('By the system'));
        $bySystem = $this->lastEntry()->id;
        $this->runner()->run(new CreateNote('By the admin'), $this->as($this->admin));
        $byAdmin = $this->lastEntry()->id;

        $this->assertDenied(new UndoAction($bySuperuser), $this->admin);
        $this->assertDenied(new UndoAction($bySystem), $this->admin);
        $this->runner()->run(new UndoAction($byAdmin), $this->as($this->superuser));
        $this->runner()->run(new UndoAction($bySuperuser), $this->as($this->superuser));

        self::assertSame(['By the system'], array_map(static fn($note): string => $note->title, $this->notes()->fetch()->all()));
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $email, array $roles): User
    {
        return $this->runner()->run(new CreateUser($email, explode('@', $email)[0], null, $roles))->user;
    }

    private function as(User $user): Context
    {
        return new Context(Actor::user($user->id, $user->name), Channel::Api);
    }

    /**
     * @param Action<mixed> $action
     */
    private function assertDenied(Action $action, User $user): void
    {
        try {
            $this->runner()->run($action, $this->as($user));
            self::fail(\sprintf('%s was allowed.', $action::class));
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
    }
}
