<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use DateTimeImmutable;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\SetRoles;
use Frootbox\Core\User\UpdateUser;
use Frootbox\Core\User\User;
use Frootbox\Core\User\UserKind;
use Frootbox\Core\User\Users;

/**
 * Accounts that a provider manages (decision 0322): created or linked,
 * given exactly one standard role, locked and unlocked. Each step is an
 * action of its own, run in the given context, so Gate and Hierarchy
 * hold as in the backend.
 */
final readonly class OidcAccounts
{
    public function __construct(
        private Runner $runner,
        private Users $users,
        private UserIdentities $identities,
    ) {}

    /**
     * Creates the account, links one with the same email, or updates the
     * one already linked.
     *
     * @return array{result: 'created'|'linked'|'updated', user: User}
     */
    public function grant(Provider $provider, string $subject, string $email, string $name, string $role, Context $context): array
    {
        $identity = $this->identities->findBySubject($provider->issuer, $subject);

        if (null !== $identity) {
            $user = $this->users->getById($identity->userId);

            // A linked account keeps its local roles.
            if ($identity->managed) {
                $this->apply($user, $role, $context);
            }

            return ['result' => 'updated', 'user' => $this->users->getById($user->id)];
        }

        $user = $this->users->findByEmail($email);

        if (null === $user) {
            $user = $this->runner->run(new CreateUser($email, $name, roles: [$role]), $context)->user;
            $this->link($provider, $subject, $user->id, managed: true);

            return ['result' => 'created', 'user' => $user];
        }

        if (UserKind::Service === $user->kind) {
            throw OidcException::serviceAccount();
        }

        if (null !== $this->identities->findForUser($user->id, $provider->key)) {
            throw OidcException::linkedToOther($provider->title);
        }

        $this->apply($user, $role, $context);
        $this->link($provider, $subject, $user->id, managed: true);

        return ['result' => 'linked', 'user' => $this->users->getById($user->id)];
    }

    /**
     * Exactly the one standard role everywhere, unlocked. Roles within a
     * scope stay, as user.set-roles keeps them.
     */
    public function apply(User $user, string $role, Context $context): void
    {
        $this->runner->run(new SetRoles($user->id, [$role]), $context);

        if (!$user->active) {
            $this->runner->run(new UpdateUser($user->id, $user->email, $user->name, true), $context);
        }
    }

    public function link(Provider $provider, string $subject, int $userId, bool $managed): UserIdentity
    {
        return $this->identities->insert([
            'userId' => $userId,
            'provider' => $provider->key,
            'issuer' => $provider->issuer,
            'subject' => $subject,
            'managed' => $managed,
            'createdAt' => new DateTimeImmutable(),
            'lastUsedAt' => $managed ? null : new DateTimeImmutable(),
        ]);
    }
}
