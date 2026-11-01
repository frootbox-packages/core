<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Auth\Passwords;
use Frootbox\Core\Config\Config;
use LogicException;

/**
 * Whether the website is protected and who may enter (decisions 0153,
 * 0154): the setting in the database, or SITE_PROTECT=true in .env. A
 * visitor who entered the password gets a cookie signed with APP_SECRET
 * that holds the generation of the password, so a new one locks out all.
 */
final class SiteProtection
{
    public const string COOKIE = 'frootbox_site_access';

    /** How long a browser stays let in. */
    public const int DAYS = 14;

    public const string ENVIRONMENT = 'SITE_PROTECT';

    private ?Protection $current = null;

    private bool $read = false;

    public function __construct(
        private readonly Protections $protections,
        private readonly Config $config,
        private readonly Passwords $passwords,
    ) {}

    public function on(): bool
    {
        return $this->forced() || null !== $this->current();
    }

    public function forced(): bool
    {
        return \in_array(strtolower(trim($this->config->env(self::ENVIRONMENT) ?? '')), ['1', 'true', 'yes', 'on'], true);
    }

    public function status(): ProtectionStatus
    {
        $current = $this->current();

        return new ProtectionStatus($this->on(), $this->forced(), null !== $current, $current->updatedAt ?? $current?->createdAt);
    }

    public function verify(string $password): bool
    {
        return $this->passwords->verify($password, $this->current()?->passwordHash);
    }

    public function hash(string $password): string
    {
        return $this->passwords->hash($password);
    }

    /**
     * The value of the cookie for a visitor who entered the password.
     */
    public function grant(int $now = 0): string
    {
        $generation = $this->current()->generation ?? throw new LogicException('The website has no password.');
        $expires = (0 === $now ? time() : $now) + self::DAYS * 86400;
        $payload = $generation . '.' . $expires;

        return $payload . '.' . $this->sign($payload);
    }

    /**
     * Whether the cookie of a visitor still lets them in.
     */
    public function grants(?string $cookie, int $now = 0): bool
    {
        $generation = $this->current()?->generation;

        if (null === $cookie || null === $generation || 3 !== \count($parts = explode('.', $cookie))) {
            return false;
        }

        [$cookieGeneration, $expires, $signature] = $parts;

        return hash_equals($this->sign($cookieGeneration . '.' . $expires), $signature)
            && hash_equals($generation, $cookieGeneration)
            && ctype_digit($expires)
            && (int) $expires > (0 === $now ? time() : $now);
    }

    /**
     * Forgets what was read, e.g. after the password changed.
     */
    public function clear(): void
    {
        $this->current = null;
        $this->read = false;
    }

    private function current(): ?Protection
    {
        if (!$this->read) {
            $this->current = $this->protections->current();
            $this->read = true;
        }

        return $this->current;
    }

    private function sign(string $payload): string
    {
        $secret = $this->config->env('APP_SECRET');

        if (null === $secret || \strlen($secret) < 16) {
            throw new LogicException('APP_SECRET is missing or shorter than 16 characters. Add a long random value to .env, e.g. from: php -r "echo bin2hex(random_bytes(32));"');
        }

        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'site-access:' . $payload, $secret, true)), '+/', '-_'), '=');
    }
}
