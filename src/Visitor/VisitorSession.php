<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Visitor;

use DateTimeImmutable;
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * The session of the visitor of the current request (decision 0312),
 * e.g. for a cart or a signed-in customer of a shop. Values are kept per
 * package:
 *
 *     $session->set('acme/shop', 'cart', ['p1' => 2]);
 *     $session->get('acme/shop', 'cart');
 *
 * The cookie only comes with the first value, never from just looking
 * at a page. A request with a session gets nothing from the page cache.
 * Without a request of a browser (console, REST API, MCP) there is no
 * session: available() is false and set() throws.
 *
 * Secrets in the session are for the package to encrypt, with
 * Security\Secrets and a purpose of its own.
 */
final class VisitorSession
{
    public const string COOKIE = 'fb_visitor';

    /** The last request is written at most this often. */
    private const int TOUCH_SECONDS = 300;

    private bool $available = false;

    /** The cookie of the browser, or the new one. */
    private ?string $cookie = null;

    private bool $loaded = false;

    /** @var array<string, array<string, mixed>>|null null without a session */
    private ?array $data = null;

    private bool $stored = false;

    private ?DateTimeImmutable $seenAt = null;

    private bool $changed = false;

    /** A stored session that no longer counts, deleted at the end. */
    private ?string $dropped = null;

    /** Whether the browser is to forget its cookie. */
    private bool $forget = false;

    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * Starts the session of a request with the cookie of the browser.
     *
     * @internal for the middleware
     */
    public function begin(?string $cookie): void
    {
        $this->available = true;
        $this->cookie = null !== $cookie && 1 === preg_match('/^[0-9a-f]{64}$/', $cookie) ? $cookie : null;
        $this->loaded = false;
        $this->data = null;
        $this->stored = false;
        $this->seenAt = null;
        $this->changed = false;
        $this->dropped = null;
        $this->forget = null === $this->cookie && null !== $cookie && '' !== $cookie;
    }

    /**
     * Ends the request: no session until the next begin().
     *
     * @internal for the middleware
     */
    public function end(): void
    {
        $this->begin(null);
        $this->available = false;
    }

    /**
     * Whether this request can keep a session, i.e. comes from a browser.
     */
    public function available(): bool
    {
        return $this->available;
    }

    /**
     * Whether the visitor has a session with values.
     */
    public function active(): bool
    {
        $this->load();

        return null !== $this->data && [] !== $this->data;
    }

    public function get(string $package, string $key, mixed $default = null): mixed
    {
        $this->load();

        return $this->data[$package][$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(string $package): array
    {
        $this->load();

        return $this->data[$package] ?? [];
    }

    /**
     * @param mixed $value anything JSON can hold
     */
    public function set(string $package, string $key, mixed $value): void
    {
        if (!$this->available) {
            throw new LogicException('There is no visitor session without a request of a browser.');
        }

        $this->load();

        if (null === $this->data) {
            $this->data = [];
            $this->cookie = self::newCookie();
        }

        $this->data[$package][$key] = $value;
        $this->changed = true;
    }

    public function remove(string $package, string $key): void
    {
        $this->load();

        if (!isset($this->data[$package]) || !\array_key_exists($key, $this->data[$package])) {
            return;
        }

        unset($this->data[$package][$key]);

        if ([] === $this->data[$package]) {
            unset($this->data[$package]);
        }

        $this->changed = true;
    }

    /**
     * Removes the values of a package, or with null the whole session.
     */
    public function clear(?string $package = null): void
    {
        $this->load();

        if (null === $this->data) {
            return;
        }

        if (null === $package) {
            $this->data = [];
        } else {
            unset($this->data[$package]);
        }

        $this->changed = true;
    }

    /**
     * Gives the session a new cookie with the same values, e.g. when a
     * customer signs in or out, so a cookie seen before counts no more.
     */
    public function regenerate(): void
    {
        $this->load();

        if (null === $this->data || !$this->stored) {
            return;
        }

        $this->dropped = $this->cookie;
        $this->forget = true;
        $this->cookie = self::newCookie();
        $this->stored = false;
        $this->changed = true;
    }

    /**
     * Writes the session and returns the headers Set-Cookie for the
     * response.
     *
     * @internal for the middleware
     *
     * @return list<string>
     */
    public function finish(bool $secure): array
    {
        $now = new DateTimeImmutable();
        $headers = [];
        $forget = $this->forget ? [self::cookie('', 0, $secure)] : [];

        if (null !== $this->dropped) {
            $this->sessions()->delete($this->dropped);
            $this->dropped = null;
        }

        if (null === $this->data || null === $this->cookie) {
            return $forget;
        }

        $sessions = $this->sessions();

        if ([] === $this->data) {
            // Nothing left: the visitor gets the cached pages again.
            if ($this->stored) {
                $sessions->delete($this->cookie);
                $this->stored = false;

                return [self::cookie('', 0, $secure)];
            }

            return $forget;
        }

        if (!$this->stored) {
            $sessions->create($this->cookie, $this->data, $now);
        } elseif ($this->changed) {
            $sessions->save($this->cookie, $this->data, $now);
        } elseif (null === $this->seenAt || $now->getTimestamp() - $this->seenAt->getTimestamp() >= self::TOUCH_SECONDS) {
            $sessions->save($this->cookie, null, $now);
        } else {
            return [];
        }

        $this->stored = true;
        $this->changed = false;
        $this->seenAt = $now;
        $this->forget = false;

        return [self::cookie($this->cookie, VisitorSessions::DAYS * 86400, $secure)];
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (null === $this->cookie) {
            return;
        }

        $session = $this->sessions()->find($this->cookie, new DateTimeImmutable());

        if (null === $session) {
            // Unknown or expired: the browser forgets it.
            $this->forget = true;
            $this->cookie = null;

            return;
        }

        $this->data = $session['data'];
        $this->seenAt = $session['seenAt'];
        $this->stored = true;
    }

    private function sessions(): VisitorSessions
    {
        $sessions = $this->container->get(VisitorSessions::class);

        return $sessions instanceof VisitorSessions ? $sessions : throw new LogicException('The visitor sessions are not available.');
    }

    private static function newCookie(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function cookie(string $value, int $seconds, bool $secure): string
    {
        $parts = [self::COOKIE . '=' . $value, 'Path=/', 'HttpOnly', 'SameSite=Lax', 'Max-Age=' . $seconds];

        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
