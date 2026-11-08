<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\User\User;
use Frootbox\Core\User\Users;

/**
 * Changes the own name (decision 0341). The email goes through
 * account.request-email-change.
 *
 * @implements Action<User>
 */
#[AsAction('account.update', 'Changes the name of the own account; the email changes with account.request-email-change', signedIn: true)]
final readonly class UpdateAccount implements Action
{
    public function __construct(
        #[Length(min: 1, max: 255)]
        public string $name,
    ) {}

    public function handle(Users $users, CurrentUser $current): User
    {
        if ('' === trim($this->name)) {
            throw new InvalidInputException('account.update', ['/name' => 'is required.']);
        }

        return $users->update($current->id(), ['name' => trim($this->name)]);
    }
}
