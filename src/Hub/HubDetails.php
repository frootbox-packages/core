<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

/**
 * The connection to the hub as the backend shows it (decision 0284).
 * state is "none", "pending" or "connected"; hint says why the website
 * cannot connect, e.g. without APP_SECRET or on a local address.
 */
final readonly class HubDetails
{
    /**
     * @param array{status: bool, packages: bool, login: bool} $switches
     */
    public function __construct(
        public string $state,
        public ?string $hubUrl,
        public ?string $siteUrl,
        public ?string $hint,
        public ?string $requestId,
        public ?string $requestUrl,
        public ?string $requestExpiresAt,
        public ?string $connectionId,
        public ?string $organization,
        public ?string $connectedAt,
        public array $switches,
        public ?string $publicKey,
        public ?string $statusUrl,
        public ?string $lastPoll,
        /** The highest role the hub may give while "login" is on (decision 0322) */
        public ?string $loginMaxRole = null,
    ) {}
}
