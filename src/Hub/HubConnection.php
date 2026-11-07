<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * The website at the hub (decision 0284): its key pair, the keys of the
 * hub, an open request or the connection with its switches.
 */
final readonly class HubConnection extends Entity
{
    /**
     * @param list<string> $hubKeys Public keys "whpk_…" of the hub
     */
    public function __construct(
        public int $id,
        public string $hubUrl,
        public string $publicKey,
        public string $secretKey,
        public array $hubKeys,
        public ?string $requestId,
        public ?DateTimeImmutable $requestExpiresAt,
        public ?string $connectionId,
        public ?string $organization,
        public bool $status,
        public bool $packages,
        public bool $login,
        public ?int $userId,
        public ?DateTimeImmutable $connectedAt,
        public DateTimeImmutable $createdAt,
        /** The highest role the hub may give, null while the switch "login" is off (decision 0322) */
        public ?string $loginMaxRole = null,
    ) {}

    public function connected(): bool
    {
        return null !== $this->connectionId;
    }

    /**
     * Whether a request waits for the confirmation on the hub.
     */
    public function pending(?DateTimeImmutable $now = null): bool
    {
        return null !== $this->requestId && null !== $this->requestExpiresAt && $this->requestExpiresAt > ($now ?? new DateTimeImmutable());
    }
}
