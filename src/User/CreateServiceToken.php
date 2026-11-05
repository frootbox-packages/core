<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\Hierarchy;
use Frootbox\Core\Auth\IssuedToken;
use Frootbox\Core\Auth\TokenType;

/**
 * Creates an API token for a service account (decisions 0180, 0285). It
 * acts with the roles of the service account and is shown only once.
 * People create their own tokens with account.create-token, so nobody
 * gets a token that acts as another person.
 *
 * @implements Action<IssuedToken>
 */
#[AsAction('user.create-token', 'Creates an API token for a service account, shown only once', permission: 'user.update', undoable: false)]
final readonly class CreateServiceToken implements Action
{
    public function __construct(
        #[Description('The service account')]
        public int $id,
        #[Length(min: 1, max: 255)]
        #[Description('What the token is for, e.g. "Status for monitoring"')]
        public string $name,
        #[Range(min: 1, max: 3650)]
        #[Description('Valid for this many days, without it until revoked')]
        public ?int $days = null,
    ) {}

    public function handle(Users $users, AccessTokens $tokens, Hierarchy $hierarchy): IssuedToken
    {
        $hierarchy->assertMayManageUser($this->id);

        if (UserKind::Service !== $users->getById($this->id)->kind) {
            throw UserException::notAServiceAccount($this->id);
        }

        $expires = null === $this->days ? null : new DateTimeImmutable()->modify(\sprintf('+%d days', $this->days));

        return $tokens->issue($this->id, TokenType::Api, $this->name, $expires);
    }
}
