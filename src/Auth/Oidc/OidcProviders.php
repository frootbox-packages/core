<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

/**
 * The entry of a source of providers in the registry (decision 0322).
 */
final readonly class OidcProviders
{
    /**
     * @param class-string<ProviderSource> $source
     */
    public function __construct(public string $source) {}
}
