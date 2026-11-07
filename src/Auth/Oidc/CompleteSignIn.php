<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Bookkeeping;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\ActorType;
use Frootbox\Core\Auth\SignInThrottle;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\User\RoleAssignments;
use Frootbox\Core\User\UserKind;
use Frootbox\Core\User\Users;

/**
 * The return from a provider (decision 0322): redeems the code, checks
 * the ID token, finds or creates the account, sets its role and starts a
 * session like account.sign-in. After account.oidc-link it links the
 * signed-in account instead.
 *
 * - Known and managed by the provider: exactly the one standard role,
 *   min(role from the provider, maxRole).
 * - Known and linked by its owner: local roles stay; refused if they rank
 *   above what the provider may give.
 * - Unknown: a new account managed by the provider, never a link to a
 *   local account with the same email.
 *
 * @implements Action<CompletedSignIn>
 */
#[AsAction('account.oidc-complete', 'Completes signing in through a provider of OpenID Connect and starts a session', public: true, undoable: false)]
final readonly class CompleteSignIn implements Action
{
    public function __construct(
        #[Length(min: 1, max: 50)]
        public string $provider,
        #[Sensitive]
        #[Length(min: 1, max: 2000)]
        public string $code,
        #[Length(min: 1, max: 200)]
        public string $state,
        #[Sensitive]
        #[Length(min: 1, max: 4000)]
        public string $stored,
        #[Length(max: 255)]
        public ?string $device = null,
    ) {}

    public function handle(Providers $providers, OidcClient $client, SignInStates $states, UserIdentities $identities, Users $users, RoleAssignments $assignments, RoleLimit $limit, OidcAccounts $accounts, AccessTokens $tokens, SignInThrottle $throttle, Bookkeeping $bookkeeping, CurrentContext $context): CompletedSignIn
    {
        $current = $context->get();
        $throttle->checkProvider($this->provider, $current->address);

        try {
            $provider = $providers->get($this->provider);
            $state = $states->decode($this->stored, $provider->key, $this->state);
            $claims = $client->signIn($provider, $this->code, $state);
        } catch (OidcException $error) {
            // After the rollback of this action, so the failure counts.
            $bookkeeping->afterwards(fn() => $throttle->failProvider($this->provider, $current->address));

            throw $error;
        }

        $subject = \is_string($claims['sub'] ?? null) ? $claims['sub'] : throw OidcException::failed('no subject');
        $identity = $identities->findBySubject($provider->issuer, $subject);

        if (null !== $state->linkUserId) {
            if (ActorType::User !== $current->actor->type || $current->actor->id !== $state->linkUserId) {
                throw OidcException::badState();
            }

            if (null !== $identity && $identity->userId !== $state->linkUserId) {
                throw OidcException::linkedToOther($provider->title);
            }

            if (null !== $identity || null !== $identities->findForUser($state->linkUserId, $provider->key)) {
                throw OidcException::alreadyLinked($provider->title);
            }

            $accounts->link($provider, $subject, $state->linkUserId, managed: false);

            return new CompletedSignIn($users->getById($state->linkUserId), true, $state->next);
        }

        $role = $limit->cap($provider->role($claims), $provider->maxRole);
        $system = Context::system(Channel::Web);

        if (null === $role) {
            throw OidcException::noAccess($provider->title);
        }

        if (null === $identity) {
            $email = \is_string($claims['email'] ?? null) ? $claims['email'] : '';

            if ('' === $email || null !== $users->findByEmail($email)) {
                throw '' === $email ? OidcException::failed('no email') : OidcException::emailTaken($provider->title);
            }

            if (true !== ($claims['email_verified'] ?? null)) {
                throw OidcException::emailUnverified($provider->title);
            }

            $name = \is_string($claims['name'] ?? null) && '' !== trim($claims['name']) ? mb_substr(trim($claims['name']), 0, 255) : $email;
            $user = $accounts->grant($provider, $subject, $email, $name, $role, $system)['user'];
            $identity = $identities->findBySubject($provider->issuer, $subject) ?? throw OidcException::failed('the link is missing');
        } else {
            $user = $users->getById($identity->userId);

            if (UserKind::Service === $user->kind) {
                throw OidcException::serviceAccount();
            }

            if ($identity->managed) {
                $accounts->apply($user, $role, $system);
                $user = $users->getById($user->id);
            } elseif (!$user->active) {
                throw OidcException::blocked();
            } elseif ($assignments->globalLevel($user->id) > $limit->level($role)) {
                throw OidcException::ranksHigher($provider->title);
            }
        }

        $identities->update($identity->id, ['lastUsedAt' => new DateTimeImmutable()]);
        $session = \is_string($claims['sid'] ?? null) ? mb_substr($claims['sid'], 0, 255) : null;
        $issued = $tokens->issue($user->id, TokenType::Session, $this->device, provider: $provider->key, providerSession: $session);

        return new CompletedSignIn($user, false, $state->next, $issued->token, $issued->accessToken);
    }
}
