<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Security;

use InvalidArgumentException;

/**
 * Signs and verifies webhooks after the open specification "Standard
 * Webhooks" (decisions 0180, 0253, 0284), over "<id>.<timestamp>.<body>":
 *
 * - symmetric "v1": HMAC-SHA256 with a shared secret "whsec_<base64>";
 * - asymmetric "v1a": Ed25519 with a secret key "whsk_<base64>", verified
 *   with its public key "whpk_<base64>". Both are the raw 32 bytes, as in
 *   the extension "endpoint verification" of the specification; the
 *   secret key is the seed of RFC 8032.
 *
 * Outgoing webhooks use it in frootbox/webhooks, an extension checks
 * incoming ones:
 *
 *     $headers = array_map(fn($values) => $values[0], $request->getHeaders());
 *     if (!StandardWebhooks::verify($secret, $headers, (string) $request->getBody())) {
 *         return new Response(401);
 *     }
 */
final class StandardWebhooks
{
    public const string PREFIX = 'whsec_';

    public const string SECRET_KEY_PREFIX = 'whsk_';

    public const string PUBLIC_KEY_PREFIX = 'whpk_';

    /** How far the time of a webhook may be from now, in seconds. */
    public const int TOLERANCE = 300;

    /**
     * A new secret, e.g. "whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw".
     */
    public static function secret(): string
    {
        return self::PREFIX . base64_encode(random_bytes(24));
    }

    /**
     * A new Ed25519 key pair for "v1a".
     *
     * @return array{secret: string, public: string}
     */
    public static function keyPair(): array
    {
        $secret = self::SECRET_KEY_PREFIX . base64_encode(random_bytes(\SODIUM_CRYPTO_SIGN_SEEDBYTES));

        return ['secret' => $secret, 'public' => self::publicKey($secret)];
    }

    /**
     * The public key "whpk_…" of a secret key "whsk_…".
     *
     * @throws InvalidArgumentException for no secret key
     */
    public static function publicKey(string $secretKey): string
    {
        return self::PUBLIC_KEY_PREFIX . base64_encode(sodium_crypto_sign_publickey(self::keyPairOf($secretKey)));
    }

    /**
     * The value of the header webhook-signature, "v1,<base64>" for a
     * secret "whsec_…", "v1a,<base64>" for a secret key "whsk_…".
     *
     * @throws InvalidArgumentException for a secret that is no base64
     */
    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        return self::signContent($secret, $id . '.' . $timestamp . '.' . $body);
    }

    /**
     * A signature of any content, e.g. of an answer to a challenge.
     * Content of a webhook always holds two dots, so content without dots
     * can never pass as a webhook.
     *
     * @throws InvalidArgumentException for a secret that is no base64
     */
    public static function signContent(string $secret, string $content): string
    {
        if (str_starts_with($secret, self::SECRET_KEY_PREFIX)) {
            return 'v1a,' . base64_encode(sodium_crypto_sign_detached($content, sodium_crypto_sign_secretkey(self::keyPairOf($secret))));
        }

        return 'v1,' . base64_encode(hash_hmac('sha256', $content, self::key($secret), true));
    }

    /**
     * The three headers of a webhook sent now.
     *
     * @return array{webhook-id: string, webhook-timestamp: string, webhook-signature: string}
     */
    public static function headers(string $secret, string $id, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => self::sign($secret, $id, $timestamp, $body),
        ];
    }

    /**
     * Whether a webhook is signed with the secret, or with one of the
     * public keys "whpk_…", and was sent within the tolerance. The header
     * names are case-insensitive; webhook-signature may hold several
     * signatures separated by spaces, e.g. while a secret changes.
     *
     * @param string|list<string>   $keys
     * @param array<string, string> $headers
     */
    public static function verify(string|array $keys, array $headers, string $body, ?int $now = null): bool
    {
        $headers = array_change_key_case($headers);
        $id = $headers['webhook-id'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        $signatures = $headers['webhook-signature'] ?? '';

        if ('' === $id || !ctype_digit($timestamp) || '' === $signatures) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        return self::verifyContent($keys, $id . '.' . $timestamp . '.' . $body, $signatures);
    }

    /**
     * Whether one of the signatures, separated by spaces, belongs to the
     * content and the secret or one of the public keys.
     *
     * @param string|list<string> $keys
     */
    public static function verifyContent(string|array $keys, string $content, string $signatures): bool
    {
        foreach ((array) $keys as $key) {
            foreach (explode(' ', $signatures) as $signature) {
                if (self::matches($key, $content, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function matches(string $key, string $content, string $signature): bool
    {
        if (!str_starts_with($key, self::PUBLIC_KEY_PREFIX)) {
            try {
                return hash_equals(self::signContent($key, $content), $signature);
            } catch (InvalidArgumentException) {
                return false;
            }
        }

        $public = base64_decode(substr($key, \strlen(self::PUBLIC_KEY_PREFIX)), true);
        $raw = str_starts_with($signature, 'v1a,') ? base64_decode(substr($signature, 4), true) : false;

        if (!\is_string($public) || \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($public) || !\is_string($raw) || \SODIUM_CRYPTO_SIGN_BYTES !== \strlen($raw)) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($raw, $content, $public);
    }

    /**
     * @return non-empty-string
     */
    private static function keyPairOf(string $secretKey): string
    {
        $seed = str_starts_with($secretKey, self::SECRET_KEY_PREFIX) ? base64_decode(substr($secretKey, \strlen(self::SECRET_KEY_PREFIX)), true) : false;

        if (!\is_string($seed) || \SODIUM_CRYPTO_SIGN_SEEDBYTES !== \strlen($seed)) {
            throw new InvalidArgumentException('A secret key is "whsk_" and the base64 of 32 bytes.');
        }

        return sodium_crypto_sign_seed_keypair($seed);
    }

    private static function key(string $secret): string
    {
        $encoded = str_starts_with($secret, self::PREFIX) ? substr($secret, \strlen(self::PREFIX)) : $secret;
        $key = base64_decode($encoded, true);

        if (false === $key || '' === $key) {
            throw new InvalidArgumentException('A webhook secret is "whsec_" and base64.');
        }

        return $key;
    }
}
