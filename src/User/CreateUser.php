<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Email;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\Passwords;

/**
 * @implements Action<UserProfile>
 */
#[AsAction('user.create', 'Creates a user who signs in with email and password')]
final readonly class CreateUser implements Action
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[Email]
        #[Length(max: 255)]
        public string $email,
        #[Length(min: 1, max: 255)]
        public string $name,
        #[Sensitive]
        #[Length(min: SetPassword::MIN_LENGTH, max: SetPassword::MAX_LENGTH)]
        #[Description('Without a password the user cannot sign in until one is set')]
        public ?string $password = null,
        #[ListOf('string')]
        #[Description('Role names, e.g. ["editor"]')]
        public array $roles = [],
        public bool $active = true,
    ) {}

    public function handle(Users $users, RoleAssignments $assignments, Passwords $passwords, Runner $runner): UserProfile
    {
        if (null !== $users->findByEmail($this->email)) {
            throw new InvalidInputException('user.create', ['/email' => 'is already in use.']);
        }

        $user = $users->insert([
            'email' => Users::normalizeEmail($this->email),
            'name' => $this->name,
            'password' => null === $this->password ? null : $passwords->hash($this->password),
            'active' => $this->active,
        ]);

        foreach ($this->roles as $role) {
            $runner->run(new AssignRole($user->id, $role));
        }

        return new UserProfile($user, $assignments->rolesOf($user->id));
    }
}
