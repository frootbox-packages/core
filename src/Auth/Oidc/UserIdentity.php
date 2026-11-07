<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * The link of a user to an account at a provider of OpenID Connect
 * (decision 0322). A managed identity takes its role and lock from the
 * provider, e.g. the hub; a linked one keeps the local roles.
 */
final readonly class UserIdentity extends Entity
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $provider,
        public string $issuer,
        public string $subject,
        public bool $managed,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt,
    ) {}
}
