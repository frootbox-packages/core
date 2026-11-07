<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

/**
 * How the website proves itself at the token endpoint of a provider.
 */
interface ClientAuthentication
{
    /**
     * The parameters for the request to the token endpoint, e.g.
     * client_assertion for private_key_jwt.
     *
     * @return array<string, string>
     */
    public function parameters(string $clientId, string $tokenEndpoint): array;
}
