<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\User\Users;

/**
 * @implements Query<AccountLanguages>
 */
#[AsQuery('account.languages', 'Lists the languages of the backend, the chosen one and the one without a choice', signedIn: true)]
final readonly class ListLanguages implements Query
{
    public function handle(Translator $translator, Users $users, CurrentUser $current): AccountLanguages
    {
        $languages = [];

        foreach ($translator->available() as $code => $name) {
            $languages[] = ['code' => $code, 'name' => $name];
        }

        return new AccountLanguages($languages, $users->getById($current->id())->language, $translator->standard(), $translator->language());
    }
}
