<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use DateTimeImmutable;

/**
 * Whether the website is protected by a password, as site.show tells it.
 */
final readonly class ProtectionStatus
{
    public function __construct(
        public bool $on,
        /** Whether SITE_PROTECT in .env forces it, so it cannot be switched off here (decision 0154) */
        public bool $forced,
        /** Whether visitors can enter a password; forced without one, only signed-in users and preview links get in */
        public bool $password,
        /** Since when the current password is set */
        public ?DateTimeImmutable $since,
    ) {}
}
