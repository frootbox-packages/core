<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Security;

use InvalidArgumentException;

/**
 * Signs and verifies webhooks after the open specification "Standard
 * Webhooks" (decisions 0180, 0253): an HMAC-SHA256 over
 * "<id>.<timestamp>.<body>" with a secret "whsec_<base64>". Outgoing
 * webhooks use it in frootbox/webhooks, an extension checks incoming ones:
 *
 *     $headers = array_map(fn($values) => $values[0], $request->getHeaders());
 *     if (!StandardWebhooks::verify($secret, $headers, (string) $request->getBody())) {
 *         return new Response(401);
 *     }
 */
final class StandardWebhooks
{
    public const string PREFIX = 'whsec_';

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
     * The value of the header webhook-signature, e.g. "v1,K5oZfzN95Z9UVu1EsfQmfVNQhnkZ2pj9o9NDN/H/pI4=".
     *
     * @throws InvalidArgumentException for a secret that is no base64
     */
    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        return 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, self::key($secret), true));
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
     * Whether a webhook is signed with the secret and was sent within the
     * tolerance. The header names are case-insensitive; webhook-signature
     * may hold several signatures separated by spaces, e.g. while a
     * secret changes.
     *
     * @param array<string, string> $headers
     */
    public static function verify(string $secret, array $headers, string $body, ?int $now = null): bool
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

        try {
            $expected = self::sign($secret, $id, (int) $timestamp, $body);
        } catch (InvalidArgumentException) {
            return false;
        }

        foreach (explode(' ', $signatures) as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
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
