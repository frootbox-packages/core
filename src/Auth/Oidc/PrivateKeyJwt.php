<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Closure;
use Firebase\JWT\JWT;
use Frootbox\Core\Security\StandardWebhooks;
use Frootbox\Database\Ulid;

/**
 * private_key_jwt after RFC 7523 and OIDC Core 9 (decision 0322): a short
 * assertion signed with EdDSA and a secret key "whsk_…", e.g. the key of
 * the connection to the hub. No client secret to keep.
 */
final readonly class PrivateKeyJwt implements ClientAuthentication
{
    public const string TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /**
     * @param Closure(): string $secretKey The key "whsk_…", read only when needed
     */
    public function __construct(private Closure $secretKey) {}

    public function parameters(string $clientId, string $tokenEndpoint): array
    {
        $now = time();
        $key = base64_encode(StandardWebhooks::signingKey(($this->secretKey)()));
        $assertion = JWT::encode([
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $tokenEndpoint,
            'jti' => Ulid::generate(),
            'iat' => $now,
            'exp' => $now + 60,
        ], $key, 'EdDSA');

        return ['client_assertion_type' => self::TYPE, 'client_assertion' => $assertion];
    }
}
