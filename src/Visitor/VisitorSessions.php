<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Visitor;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Journal;
use Frootbox\Database\DirectWriter;
use Frootbox\Database\Values;

/**
 * The table of visitor sessions (decision 0312). Like access tokens they
 * are bookkeeping, written directly and never recorded for undoing. The
 * table keeps only the SHA-256 hash of the cookie.
 */
final readonly class VisitorSessions
{
    public const string TABLE = 'visitor_sessions';

    /** A session ends this long after the last request. */
    public const int DAYS = 30;

    private DirectWriter $writer;

    public function __construct(
        private Connection $connection,
        private Journal $journal,
    ) {
        $this->writer = new DirectWriter($connection);
    }

    /**
     * The stored session of a cookie, null if it is unknown or expired.
     *
     * @return array{data: array<string, array<string, mixed>>, seenAt: DateTimeImmutable}|null
     */
    public function find(string $cookie, DateTimeImmutable $now): ?array
    {
        $row = $this->writer->find(self::TABLE, self::hash($cookie));

        if (null === $row || !\is_string($row['expires_at']) || $row['expires_at'] <= $now->format(Values::DATE_FORMAT)) {
            return null;
        }

        $seenAt = \is_string($row['seen_at']) ? DateTimeImmutable::createFromFormat(Values::DATE_FORMAT, $row['seen_at']) : false;

        return ['data' => self::decode($row['data']), 'seenAt' => false === $seenAt ? $now : $seenAt];
    }

    /**
     * @param array<string, array<string, mixed>> $data
     */
    public function create(string $cookie, array $data, DateTimeImmutable $now): void
    {
        $this->journal->trusted(fn(): array => $this->writer->insert(self::TABLE, [
            'id' => self::hash($cookie),
            'data' => self::encode($data),
            'created_at' => $now,
            'seen_at' => $now,
            'expires_at' => self::end($now),
        ]));
    }

    /**
     * @param array<string, array<string, mixed>>|null $data null keeps the data, only the last request changes
     */
    public function save(string $cookie, ?array $data, DateTimeImmutable $now): void
    {
        $changes = ['seen_at' => $now, 'expires_at' => self::end($now)];

        if (null !== $data) {
            $changes['data'] = self::encode($data);
        }

        $this->journal->trusted(fn(): array => $this->writer->update(self::TABLE, self::hash($cookie), $changes));
    }

    public function delete(string $cookie): void
    {
        $this->journal->trusted(fn(): bool => $this->writer->delete(self::TABLE, self::hash($cookie)));
    }

    /**
     * Deletes expired sessions.
     */
    public function prune(DateTimeImmutable $now): int
    {
        return $this->journal->trusted(fn(): int => (int) $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE expires_at <= ?',
            [$now->format(Values::DATE_FORMAT)],
        ));
    }

    private static function end(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(\sprintf('+%d days', self::DAYS));
    }

    private static function hash(string $cookie): string
    {
        return hash('sha256', $cookie);
    }

    /**
     * @param array<string, array<string, mixed>> $data
     */
    private static function encode(array $data): string
    {
        return json_encode((object) $data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function decode(mixed $json): array
    {
        $data = \is_string($json) ? json_decode($json, true) : null;
        $result = [];

        foreach (\is_array($data) ? $data : [] as $package => $values) {
            if (\is_string($package) && \is_array($values)) {
                $result[$package] = array_filter($values, is_string(...), \ARRAY_FILTER_USE_KEY);
            }
        }

        return $result;
    }
}
