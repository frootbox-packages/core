<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Security;

use Frootbox\Core\Config\Config;

/**
 * Encrypts secrets such as API keys of extensions for the database
 * (decision 0221), with libsodium and a key derived from APP_SECRET and
 * a purpose. A text encrypted for one purpose cannot be read for another.
 */
final readonly class Secrets
{
    private const string PREFIX = 'v1:';

    public function __construct(private Config $config) {}

    /**
     * Whether APP_SECRET is long enough to encrypt anything.
     */
    public function available(): bool
    {
        return \strlen($this->config->env('APP_SECRET') ?? '') >= 16;
    }

    public function encrypt(string $purpose, string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . sodium_bin2base64($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key($purpose)), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * Null if the text cannot be read, e.g. because APP_SECRET changed.
     */
    public function decrypt(string $purpose, string $encrypted): ?string
    {
        if (!str_starts_with($encrypted, self::PREFIX) || !$this->available()) {
            return null;
        }

        try {
            $raw = sodium_base642bin(substr($encrypted, \strlen(self::PREFIX)), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            return null;
        }

        if (\strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key($purpose));

        return false === $plain ? null : $plain;
    }

    /**
     * A signature of a text for a purpose, e.g. the time a form was shown
     * (decision 0222). Null without APP_SECRET.
     */
    public function sign(string $purpose, string $text): ?string
    {
        if (!$this->available()) {
            return null;
        }

        return sodium_bin2base64(sodium_crypto_auth($text, $this->key('sign:' . $purpose)), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * Whether the signature belongs to the text and purpose.
     */
    public function verify(string $purpose, string $text, string $signature): bool
    {
        $expected = $this->sign($purpose, $text);

        return null !== $expected && hash_equals($expected, $signature);
    }

    /**
     * @return non-empty-string
     */
    private function key(string $purpose): string
    {
        $secret = $this->config->env('APP_SECRET') ?? '';

        if (\strlen($secret) < 16) {
            throw new SecretsException('APP_SECRET is missing or shorter than 16 characters, so no secret can be stored. Add a long random value to .env, e.g. from: php -r "echo bin2hex(random_bytes(32));"');
        }

        return sodium_crypto_generichash($purpose, sodium_crypto_generichash($secret), \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
