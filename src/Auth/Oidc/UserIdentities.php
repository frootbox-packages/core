<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Database\Repository;

/**
 * Links of users to providers, written in actions like other rows, so
 * they are logged and can be undone (decision 0322).
 *
 * @extends Repository<UserIdentity>
 */
final class UserIdentities extends Repository
{
    public const string TABLE = 'user_identities';

    public function findBySubject(string $issuer, string $subject): ?UserIdentity
    {
        return $this->fetchOne(['where' => ['issuer' => $issuer, 'subject' => $subject]]);
    }

    public function findForUser(int $userId, string $provider): ?UserIdentity
    {
        return $this->fetchOne(['where' => ['userId' => $userId, 'provider' => $provider]]);
    }

    /**
     * @return list<UserIdentity>
     */
    public function forUser(int $userId): array
    {
        return $this->fetch(['where' => ['userId' => $userId], 'order' => ['provider' => 'ASC']])->all();
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return UserIdentity::class;
    }
}
