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
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\User\User;
use Frootbox\Core\User\Users;

/**
 * Sets the language of the backend for the own account (decision 0161).
 *
 * @implements Action<User>
 */
#[AsAction('account.update-language', 'Sets the language of the backend for the own account, null for automatic', signedIn: true)]
final readonly class UpdateLanguage implements Action
{
    public function __construct(
        /** A code from account.languages, or null for the standard of the installation */
        #[Length(max: 35)]
        public ?string $language = null,
    ) {}

    public function handle(Users $users, Translator $translator, CurrentUser $current): User
    {
        if (null !== $this->language && !$translator->offers($this->language)) {
            throw new InvalidInputException('account.update-language', ['/language' => \sprintf('must be one of %s, or null for automatic.', implode(', ', array_keys($translator->available())))]);
        }

        return $users->update($current->id(), ['language' => $this->language]);
    }
}
