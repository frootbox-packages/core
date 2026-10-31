<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Email;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\Auth\Hierarchy;

/**
 * @implements Action<User>
 */
#[AsAction('user.update', 'Changes the email, name or status of a user')]
final readonly class UpdateUser implements Action
{
    public function __construct(
        public int $id,
        #[Email]
        #[Length(max: 255)]
        public string $email,
        #[Length(min: 1, max: 255)]
        public string $name,
        public bool $active,
    ) {}

    public function handle(Users $users, CurrentUser $current, Hierarchy $hierarchy): User
    {
        $hierarchy->assertMayManageUser($this->id);

        $other = $users->findByEmail($this->email);

        if (null !== $other && $other->id !== $this->id) {
            throw new InvalidInputException('user.update', ['/email' => 'is already in use.']);
        }

        if (!$this->active && $current->is($this->id)) {
            throw UserException::notYourself('disable');
        }

        return $users->update($this->id, [
            'email' => Users::normalizeEmail($this->email),
            'name' => $this->name,
            'active' => $this->active,
        ]);
    }
}
