<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

/**
 * Gives the providers that are active right now, e.g. the hub once its
 * switch "login" is on. Added with
 * `$registry->add(OidcProviders::class, new OidcProviders(Source::class))`.
 */
interface ProviderSource
{
    /**
     * @return list<Provider>
     */
    public function providers(): array;
}
