<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\User\UpdateUser;
use Frootbox\Core\User\Users;

/**
 * Takes the access of a person of a provider away (decision 0322). An
 * account the provider manages is locked, not deleted, and all its
 * sessions end; log and authorship stay, a new access unlocks it. A
 * linked account only loses the link and the sessions through it.
 * Nothing else: it runs for the provider without the rights of a person.
 *
 * @implements Action<string>
 */
#[AsAction('user.revoke-access', 'Locks the account a provider such as the hub manages, or removes the link of a linked one, and ends its sessions')]
final readonly class RevokeAccess implements Action
{
    public function __construct(
        #[Length(min: 1, max: 50)]
        public string $provider,
        #[Length(min: 1, max: 255)]
        public string $subject,
    ) {}

    /**
     * @return 'blocked'|'unlinked'|'none'
     */
    public function handle(Providers $providers, UserIdentities $identities, Users $users, AccessTokens $tokens, Runner $runner): string
    {
        $provider = $providers->get($this->provider);
        $identity = $identities->findBySubject($provider->issuer, $this->subject);

        if (null === $identity) {
            return 'none';
        }

        if (!$identity->managed) {
            $identities->delete($identity->id);
            $tokens->endProviderSessions($provider->key, $identity->userId);

            return 'unlinked';
        }

        $user = $users->getById($identity->userId);

        if ($user->active) {
            $runner->run(new UpdateUser($user->id, $user->email, $user->name, false));
        }

        $tokens->revokeAll($user->id);

        return 'blocked';
    }
}
