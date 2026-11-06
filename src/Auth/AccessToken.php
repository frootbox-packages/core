<?php

/*
 * SPDX-License-Identifier: MIT
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
        public ?string $device = null,
    ) {}

    /**
     * What tells this token apart in the log of actions (decision 0174):
     * the device of a session, else the name of an API token.
     */
    public function label(): ?string
    {
        $label = TokenType::Session === $this->type ? $this->device : $this->name;

        return null === $label || '' === $label ? null : mb_substr($label, 0, 100);
    }
}
