<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

/**
 * A provider as the sign-in page shows it.
 */
final readonly class ProviderInfo
{
    public function __construct(
        public string $key,
        public string $title,
        public string $issuer,
    ) {}
}
