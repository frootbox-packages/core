<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\User\UserKind;
use Frootbox\Core\User\Users;

/**
 * Starts linking the own account to a provider (decision 0322): the same
 * way as signing in, but the return links instead. The account keeps its
 * local roles.
 *
 * @implements Action<StartedSignIn>
 */
#[AsAction('account.oidc-link', 'Starts linking the own account to a provider such as the hub and returns the address for the browser', signedIn: true, undoable: false)]
final readonly class LinkAccount implements Action
{
    public function __construct(
        #[Length(min: 1, max: 50)]
        public string $provider,
        #[Length(max: 2000)]
        public ?string $next = null,
    ) {}

    public function handle(Providers $providers, OidcClient $client, OidcPaths $paths, SignInStates $states, CurrentUser $current, Users $users, UserIdentities $identities): StartedSignIn
    {
        $provider = $providers->get($this->provider);

        if (UserKind::Service === $users->getById($current->id())->kind) {
            throw OidcException::serviceAccount();
        }

        if (null !== $identities->findForUser($current->id(), $provider->key)) {
            throw OidcException::alreadyLinked($provider->title);
        }

        $state = $states->create($provider->key, $paths->redirect($provider->key) ?? throw OidcException::failed('the address of the website is missing'), $this->next, $current->id());

        return new StartedSignIn($provider->key, $client->authorizationUrl($provider, $state, 'login'), $states->encode($state));
    }
}
