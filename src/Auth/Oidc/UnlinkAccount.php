<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\CurrentUser;

/**
 * Removes the link of the own account to a provider and ends the sessions
 * through it (decision 0322). An account the provider manages stays
 * linked: it is managed there.
 *
 * @implements Action<void>
 */
#[AsAction('account.oidc-unlink', 'Removes the link of the own account to a provider such as the hub', signedIn: true)]
final readonly class UnlinkAccount implements Action
{
    public function __construct(
        #[Length(min: 1, max: 50)]
        public string $provider,
    ) {}

    public function handle(UserIdentities $identities, AccessTokens $tokens, CurrentUser $current, Providers $providers): void
    {
        $identity = $identities->findForUser($current->id(), $this->provider) ?? throw OidcException::notLinked();

        if ($identity->managed) {
            throw OidcException::managed($providers->find($this->provider)->title ?? $this->provider);
        }

        $identities->delete($identity->id);
        $tokens->endProviderSessions($this->provider, $current->id());
    }
}
