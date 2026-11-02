<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use Frootbox\Core\Config\Config;
use LogicException;

/**
 * Tokens of preview links (decision 0152): 32 random bytes, looked up by
 * their SHA-256 and kept encrypted with APP_SECRET, so the backend can
 * show a link again for copying.
 */
final readonly class PreviewTokens
{
    private const string CIPHER = 'aes-256-gcm';

    public function __construct(private Config $config) {}

    public function create(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function encrypt(string $token): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($token, self::CIPHER, $this->key(), \OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $encrypted) {
            throw new LogicException('The token of a preview link could not be encrypted.');
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Null if APP_SECRET changed since, so the link can no longer be shown.
     */
    public function decrypt(string $stored): ?string
    {
        $data = base64_decode($stored, true);

        if (false === $data || \strlen($data) < 29) {
            return null;
        }

        $token = openssl_decrypt(substr($data, 28), self::CIPHER, $this->key(), \OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));

        return false === $token ? null : $token;
    }

    private function key(): string
    {
        $secret = $this->config->env('APP_SECRET');

        if (null === $secret || \strlen($secret) < 16) {
            throw new LogicException('APP_SECRET is missing or shorter than 16 characters. Add a long random value to .env, e.g. from: php -r "echo bin2hex(random_bytes(32));"');
        }

        return hash('sha256', 'preview-links:' . $secret, true);
    }
}
