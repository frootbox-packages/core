<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Auth\Passwords;
use Frootbox\Core\Config\Config;
use LogicException;

/**
 * Who may see a page with a password (decision 0358). A visitor who
 * entered it gets a cookie for the protecting page, signed with
 * APP_SECRET over its id and the generation of the password, so a new
 * password locks out all.
 */
final class PagePasswords
{
    /** The field of the password form. */
    public const string FIELD = 'fb-page-password';

    /** How long a browser stays let in. */
    public const int DAYS = 14;

    private const string COOKIE = 'frootbox_page_';

    /** @var array<string, PageProtection>|null */
    private ?array $protections = null;

    public function __construct(
        private readonly PageProtections $repository,
        private readonly Config $config,
        private readonly Passwords $passwords,
    ) {}

    /**
     * The protections by page.
     *
     * @return array<string, PageProtection>
     */
    public function all(): array
    {
        return $this->protections ??= $this->repository->all();
    }

    public function of(string $pageId): ?PageProtection
    {
        return $this->all()[$pageId] ?? null;
    }

    /**
     * The name of the cookie that lets a browser into a protected page.
     */
    public static function cookie(string $pageId): string
    {
        return self::COOKIE . strtolower($pageId);
    }

    public function hash(string $password): string
    {
        return $this->passwords->hash($password);
    }

    public function verify(string $pageId, string $password): bool
    {
        return $this->passwords->verify($password, $this->of($pageId)?->passwordHash);
    }

    /**
     * The value of the cookie for a visitor who entered the password.
     */
    public function grant(string $pageId, int $now = 0): string
    {
        $generation = $this->of($pageId)->generation ?? throw new LogicException('The page has no password.');
        $expires = (0 === $now ? time() : $now) + self::DAYS * 86400;
        $payload = $generation . '.' . $expires;

        return $payload . '.' . $this->sign($pageId, $payload);
    }

    /**
     * Whether the cookies of a visitor let them into the protecting page.
     *
     * @param array<array-key, mixed> $cookies
     */
    public function grants(string $pageId, array $cookies, int $now = 0): bool
    {
        $cookie = $cookies[self::cookie($pageId)] ?? null;
        $generation = $this->of($pageId)?->generation;

        if (!\is_string($cookie) || null === $generation || 3 !== \count($parts = explode('.', $cookie))) {
            return false;
        }

        [$cookieGeneration, $expires, $signature] = $parts;

        return hash_equals($this->sign($pageId, $cookieGeneration . '.' . $expires), $signature)
            && hash_equals($generation, $cookieGeneration)
            && ctype_digit($expires)
            && (int) $expires > (0 === $now ? time() : $now);
    }

    /**
     * Forgets what was read, e.g. after a password changed.
     */
    public function clear(): void
    {
        $this->protections = null;
    }

    private function sign(string $pageId, string $payload): string
    {
        $secret = $this->config->env('APP_SECRET');

        if (null === $secret || \strlen($secret) < 16) {
            throw new LogicException('APP_SECRET is missing or shorter than 16 characters. Add a long random value to .env, e.g. from: php -r "echo bin2hex(random_bytes(32));"');
        }

        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'page-access:' . strtolower($pageId) . ':' . $payload, $secret, true)), '+/', '-_'), '=');
    }
}
