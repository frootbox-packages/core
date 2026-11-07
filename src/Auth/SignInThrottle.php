<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Support\Message;
use Frootbox\Core\User\Users;
use Frootbox\Database\Values;

/**
 * Throttles failed sign-ins per account and per address (decision 0115):
 *
 * - From 5 failures for an email within 15 minutes, every further
 *   failure means waiting 1, 2, 4, 8 and at most 15 minutes. While
 *   waiting even the right password is refused.
 * - From 30 failures from one address within 15 minutes, 15 minutes break.
 *
 * Failures are bookkeeping, written directly. The table keeps only hashes
 * of the email and the address, and nothing older than a day.
 */
final readonly class SignInThrottle
{
    public const string TABLE = 'sign_in_failures';

    private const int WINDOW = 900;

    private const int ACCOUNT_LIMIT = 5;

    private const int ADDRESS_LIMIT = 30;

    private const int MAX_WAIT = 900;

    private const int RESET_EMAIL_LIMIT = 3;

    private const int RESET_ADDRESS_LIMIT = 10;

    private const int KEEP = 86400;

    public function __construct(private Connection $connection) {}

    /**
     * Throws if the account or the address has to wait.
     *
     * @throws TooManyAttemptsException
     */
    public function check(string $email, ?string $address, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $wait = 0;

        [$count, $last] = $this->failures(self::accountKey($email), $now);

        if ($count >= self::ACCOUNT_LIMIT && null !== $last) {
            $wait = max($wait, $last + min(self::MAX_WAIT, 60 * 2 ** ($count - self::ACCOUNT_LIMIT)) - $now->getTimestamp());
        }

        if (null !== $address) {
            [$count, $last] = $this->failures(self::addressKey($address), $now);

            if ($count >= self::ADDRESS_LIMIT && null !== $last) {
                $wait = max($wait, $last + self::MAX_WAIT - $now->getTimestamp());
            }
        }

        if ($wait > 0) {
            throw new TooManyAttemptsException($wait);
        }
    }

    /**
     * Counts a failed sign-in and forgets failures older than a day.
     */
    public function fail(string $email, ?string $address, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $time = $now->format(Values::DATE_FORMAT);

        $keys = null === $address ? [self::accountKey($email)] : [self::accountKey($email), self::addressKey($address)];

        foreach ($keys as $key) {
            $this->connection->insert(self::TABLE, ['key_hash' => $key, 'failed_at' => $time]);
        }

        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE failed_at < ?',
            [$now->modify(\sprintf('-%d seconds', self::KEEP))->format(Values::DATE_FORMAT)],
        );
    }

    /**
     * Throws if the address has to wait before it may try the password of
     * the protected website again (decision 0153). Its own count, so
     * guessing that password does not lock out signing in.
     *
     * @throws TooManyAttemptsException
     */
    public function checkSite(?string $address, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        [$count, $last] = $this->failures(self::siteKey($address), $now);

        if ($count >= self::ACCOUNT_LIMIT && null !== $last) {
            $wait = $last + min(self::MAX_WAIT, 60 * 2 ** ($count - self::ACCOUNT_LIMIT)) - $now->getTimestamp();

            if ($wait > 0) {
                throw new TooManyAttemptsException($wait);
            }
        }
    }

    /**
     * Counts a wrong password for the protected website.
     */
    public function failSite(?string $address, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $this->connection->insert(self::TABLE, ['key_hash' => self::siteKey($address), 'failed_at' => $now->format(Values::DATE_FORMAT)]);
    }

    /**
     * Throws if the address has to wait before it may sign in through a
     * provider again (decision 0322), counted per provider and address.
     *
     * @throws TooManyAttemptsException
     */
    public function checkProvider(string $provider, ?string $address, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        [$count, $last] = $this->failures(self::providerKey($provider, $address), $now);

        if ($count >= self::ACCOUNT_LIMIT && null !== $last) {
            $wait = $last + min(self::MAX_WAIT, 60 * 2 ** ($count - self::ACCOUNT_LIMIT)) - $now->getTimestamp();

            if ($wait > 0) {
                throw new TooManyAttemptsException($wait);
            }
        }
    }

    /**
     * Counts a failed sign-in through a provider.
     */
    public function failProvider(string $provider, ?string $address, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $this->connection->insert(self::TABLE, ['key_hash' => self::providerKey($provider, $address), 'failed_at' => $now->format(Values::DATE_FORMAT)]);
    }

    /**
     * Throws if the address asked for too many links for a forgotten
     * password (decision 0325): from 10 within 15 minutes, 15 minutes break.
     *
     * @throws TooManyAttemptsException
     */
    public function checkReset(?string $address, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        [$count, $last] = $this->failures(self::resetAddressKey($address), $now);

        if ($count >= self::RESET_ADDRESS_LIMIT && null !== $last) {
            $wait = $last + self::MAX_WAIT - $now->getTimestamp();

            if ($wait > 0) {
                throw new TooManyAttemptsException($wait, new Message('Too many requests. Try again in {minutes} minutes.', ['minutes' => max(1, (int) ceil($wait / 60))]));
            }
        }
    }

    /**
     * Whether another mail may go to this email: at most 3 within 15
     * minutes, so nobody fills an inbox with links.
     */
    public function resetMailAllowed(string $email, ?DateTimeImmutable $now = null): bool
    {
        return $this->failures(self::resetEmailKey($email), $now ?? new DateTimeImmutable())[0] < self::RESET_EMAIL_LIMIT;
    }

    /**
     * Counts a request for a link, apart from failed sign-ins.
     */
    public function countReset(string $email, ?string $address, ?DateTimeImmutable $now = null): void
    {
        $time = ($now ?? new DateTimeImmutable())->format(Values::DATE_FORMAT);

        foreach ([self::resetEmailKey($email), self::resetAddressKey($address)] as $key) {
            $this->connection->insert(self::TABLE, ['key_hash' => $key, 'failed_at' => $time]);
        }
    }

    /**
     * Forgets the failures of an account after a successful sign-in.
     */
    public function clear(string $email): void
    {
        $this->connection->delete(self::TABLE, ['key_hash' => self::accountKey($email)]);
    }

    /**
     * @return array{int, ?int} the failures within the window and the time of the last one
     */
    private function failures(string $key, DateTimeImmutable $now): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS failures, MAX(failed_at) AS last_failure FROM ' . self::TABLE . ' WHERE key_hash = ? AND failed_at > ?',
            [$key, $now->modify(\sprintf('-%d seconds', self::WINDOW))->format(Values::DATE_FORMAT)],
        );

        $count = \is_array($row) && is_numeric($row['failures'] ?? null) ? (int) $row['failures'] : 0;
        $last = \is_array($row) && \is_string($row['last_failure'] ?? null) ? new DateTimeImmutable($row['last_failure'])->getTimestamp() : null;

        return [$count, $last];
    }

    private static function accountKey(string $email): string
    {
        return hash('sha256', 'email:' . Users::normalizeEmail($email));
    }

    private static function siteKey(?string $address): string
    {
        return hash('sha256', 'site:ip:' . ($address ?? 'unknown'));
    }

    private static function providerKey(string $provider, ?string $address): string
    {
        return hash('sha256', 'oidc:' . $provider . ':ip:' . ($address ?? 'unknown'));
    }

    private static function resetEmailKey(string $email): string
    {
        return hash('sha256', 'reset:email:' . Users::normalizeEmail($email));
    }

    private static function resetAddressKey(?string $address): string
    {
        return hash('sha256', 'reset:ip:' . ($address ?? 'unknown'));
    }

    private static function addressKey(string $address): string
    {
        return hash('sha256', 'ip:' . $address);
    }
}
