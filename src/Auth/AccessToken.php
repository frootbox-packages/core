<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A session or API token. Only its hash is stored, so the token itself is
 * shown exactly once, when it is issued.
 */
final readonly class AccessToken extends Entity
{
    public function __construct(
        public int $id,
        public int $userId,
        public TokenType $type,
        public ?string $name,
        public ?DateTimeImmutable $lastUsedAt,
        public ?DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
        public bool $remember = false,
    ) {}
}
