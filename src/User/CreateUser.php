<?php

/*
 * SPDX-License-Identifier: MIT
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
#[AsAction('user.create', 'Creates a user who signs in with email and password, or a service account that only uses API tokens')]
final readonly class CreateUser implements Action
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[Email]
        #[Length(max: 255)]
        #[Description('Required for a person, none for a service account')]
        public ?string $email,
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
        #[Description('"service" for a service account: no email, no password, tokens only from user.create-token')]
        public UserKind $kind = UserKind::Person,
        #[Description('Mails the person a link to choose a password, instead of one given here')]
        public bool $invite = false,
    ) {}

    public function handle(Users $users, RoleAssignments $assignments, Passwords $passwords, Runner $runner): UserProfile
    {
        if (UserKind::Service === $this->kind) {
            $errors = array_filter([
                '/email' => null === $this->email ? null : 'must be empty for a service account.',
                '/password' => null === $this->password ? null : 'must be empty for a service account.',
                '/invite' => $this->invite ? 'must be off for a service account.' : null,
            ]);

            if ([] !== $errors) {
                throw new InvalidInputException('user.create', $errors);
            }
        } elseif ($this->invite && null !== $this->password) {
            throw new InvalidInputException('user.create', ['/password' => 'must be empty for an invitation.']);
        } elseif (null === $this->email || '' === trim($this->email)) {
            throw new InvalidInputException('user.create', ['/email' => 'is required.']);
        } elseif (null !== $users->findByEmail($this->email)) {
            throw new InvalidInputException('user.create', ['/email' => 'is already in use.']);
        }

        $user = $users->insert([
            'email' => null === $this->email ? null : Users::normalizeEmail($this->email),
            'kind' => $this->kind,
            'name' => $this->name,
            'password' => null === $this->password ? null : $passwords->hash($this->password),
            'active' => $this->active,
        ]);

        foreach ($this->roles as $role) {
            $runner->run(new AssignRole($user->id, $role));
        }

        if ($this->invite && UserKind::Person === $this->kind) {
            $runner->run(new InviteUser($user->id));
        }

        return new UserProfile($user, $assignments->rolesOf($user->id), null !== $this->password);
    }
}
