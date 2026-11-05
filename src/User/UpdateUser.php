<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Email;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\Auth\Hierarchy;

/**
 * @implements Action<User>
 */
#[AsAction('user.update', 'Changes the email, name or status of a user; a service account keeps having no email')]
final readonly class UpdateUser implements Action
{
    public function __construct(
        public int $id,
        #[Email]
        #[Length(max: 255)]
        #[Description('Required for a person, none for a service account')]
        public ?string $email,
        #[Length(min: 1, max: 255)]
        public string $name,
        public bool $active,
    ) {}

    public function handle(Users $users, CurrentUser $current, Hierarchy $hierarchy): User
    {
        $hierarchy->assertMayManageUser($this->id);

        $service = UserKind::Service === $users->getById($this->id)->kind;

        if ($service && null !== $this->email) {
            throw new InvalidInputException('user.update', ['/email' => 'must be empty for a service account.']);
        }

        if (!$service && (null === $this->email || '' === trim($this->email))) {
            throw new InvalidInputException('user.update', ['/email' => 'is required.']);
        }

        $other = null === $this->email ? null : $users->findByEmail($this->email);

        if (null !== $other && $other->id !== $this->id) {
            throw new InvalidInputException('user.update', ['/email' => 'is already in use.']);
        }

        if (!$this->active && $current->is($this->id)) {
            throw UserException::notYourself('disable');
        }

        return $users->update($this->id, [
            'email' => null === $this->email ? null : Users::normalizeEmail($this->email),
            'name' => $this->name,
            'active' => $this->active,
        ]);
    }
}
