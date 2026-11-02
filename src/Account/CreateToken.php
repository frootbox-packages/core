<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\Auth\IssuedToken;
use Frootbox\Core\Auth\TokenType;

/**
 * Creates an API token for the own account. It acts with the user's
 * permissions and is shown only once.
 *
 * @implements Action<IssuedToken>
 */
#[AsAction('account.create-token', 'Creates an API token for the own account', signedIn: true, undoable: false)]
final readonly class CreateToken implements Action
{
    public function __construct(
        #[Length(min: 1, max: 255)]
        #[Description('What the token is for, e.g. "Deploy script"')]
        public string $name,
        #[Range(min: 1, max: 3650)]
        #[Description('Valid for this many days, without it until revoked')]
        public ?int $days = null,
    ) {}

    public function handle(AccessTokens $tokens, CurrentUser $current): IssuedToken
    {
        $expires = null === $this->days ? null : new DateTimeImmutable()->modify(\sprintf('+%d days', $this->days));

        return $tokens->issue($current->id(), TokenType::Api, $this->name, $expires);
    }
}
