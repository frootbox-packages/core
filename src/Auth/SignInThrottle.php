<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
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

    private static function addressKey(string $address): string
    {
        return hash('sha256', 'ip:' . $address);
    }
}
