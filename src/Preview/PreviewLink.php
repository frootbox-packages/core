<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A link that shows the whole website in the draft to someone without an
 * account (decision 0152). The token is kept only as hash and encrypted.
 */
final readonly class PreviewLink extends Entity
{
    public function __construct(
        public string $id,
        public string $tokenHash,
        public string $token,
        public ?string $note,
        public ?string $pageId,
        public ?int $createdBy,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
        public ?DateTimeImmutable $firstOpenedAt,
        public ?DateTimeImmutable $lastOpenedAt,
        public int $opens,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    public function status(DateTimeImmutable $now = new DateTimeImmutable()): PreviewStatus
    {
        return match (true) {
            null !== $this->revokedAt => PreviewStatus::Revoked,
            $this->expiresAt <= $now => PreviewStatus::Expired,
            default => PreviewStatus::Valid,
        };
    }
}
