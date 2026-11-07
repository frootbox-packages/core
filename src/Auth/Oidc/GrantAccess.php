<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Email;
use Frootbox\Core\Action\Schema\Length;

/**
 * Gives a person of a provider access (decision 0322), e.g. when an admin
 * does so in the hub, run as the local account of that admin: creates the
 * account, links one with the same email or sets the role of the linked
 * one. Each step is its own action, with the rights and the hierarchy of
 * the one who runs it. The role is at most maxRole of the provider.
 *
 * @implements Action<AccessGranted>
 */
#[AsAction('user.grant-access', 'Gives a person of a provider such as the hub an account with one standard role, or links the account with the same email')]
final readonly class GrantAccess implements Action
{
    public function __construct(
        #[Length(min: 1, max: 50)]
        public string $provider,
        #[Length(min: 1, max: 255)]
        public string $subject,
        #[Email]
        #[Length(max: 255)]
        public string $email,
        #[Length(min: 1, max: 255)]
        public string $name,
        #[Length(min: 1, max: 50)]
        public string $role,
    ) {}

    public function handle(Providers $providers, RoleLimit $limit, OidcAccounts $accounts, CurrentContext $context): AccessGranted
    {
        $provider = $providers->get($this->provider);
        $role = $limit->cap($this->role, $provider->maxRole) ?? throw new InvalidInputException('user.grant-access', ['/role' => 'must be editor, admin or superuser.']);
        $granted = $accounts->grant($provider, $this->subject, $this->email, $this->name, $role, $context->get());

        return new AccessGranted($granted['result'], $granted['user']);
    }
}
