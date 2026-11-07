<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;

/**
 * Starts signing in through a provider (decision 0322). Writes nothing:
 * state, nonce and the PKCE verifier travel encrypted with the browser.
 *
 * @implements Action<StartedSignIn>
 */
#[AsAction('account.oidc-start', 'Starts signing in through a provider of OpenID Connect such as the hub and returns the address for the browser', public: true, undoable: false)]
final readonly class StartSignIn implements Action
{
    public function __construct(
        #[Length(min: 1, max: 50)]
        #[Description('The key of the provider, e.g. "hub"')]
        public string $provider,
        #[Length(max: 2000)]
        #[Description('Where to go after signing in, a path of the backend')]
        public ?string $next = null,
        #[Description('"none" for a silent sign-in, "login" to ask again')]
        public ?string $prompt = null,
    ) {}

    public function handle(Providers $providers, OidcClient $client, OidcPaths $paths, SignInStates $states): StartedSignIn
    {
        if (null !== $this->prompt && !\in_array($this->prompt, ['none', 'login'], true)) {
            throw new InvalidInputException('account.oidc-start', ['/prompt' => 'must be "none" or "login".']);
        }

        $provider = $providers->get($this->provider);
        $state = $states->create($provider->key, $paths->redirect($provider->key) ?? throw OidcException::failed('the address of the website is missing'), $this->next, silent: 'none' === $this->prompt);

        return new StartedSignIn($provider->key, $client->authorizationUrl($provider, $state, $this->prompt), $states->encode($state));
    }
}
