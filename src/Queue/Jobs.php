<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Auth\Actor;
use Frootbox\Database\DirectWriter;
use Frootbox\Database\EntityMapper;
use Frootbox\Database\Ulid;
use Frootbox\Database\Values;

/**
 * The table of the queue (decision 0221). Jobs are bookkeeping like
 * access tokens: written directly, inside the transaction of a running
 * action, but not recorded for undo.
 */
final readonly class Jobs
{
    public const string TABLE = 'jobs';

    private DirectWriter $writer;

    public function __construct(
        private Connection $connection,
        private Journal $journal,
    ) {
        $this->writer = new DirectWriter($connection);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function insert(string $name, array $input, Actor $actor, DateTimeImmutable $availableAt, int $maxAttempts): StoredJob
    {
        return $this->hydrate($this->journal->trusted(fn(): array => $this->writer->insert(self::TABLE, [
            'id' => Ulid::generate(),
            'name' => $name,
            'input' => $input,
            'actorType' => $actor->type->value,
            'actorId' => $actor->id,
            'actorName' => $actor->name,
            'availableAt' => $availableAt,
            'attempts' => 0,
            'maxAttempts' => $maxAttempts,
            'createdAt' => new DateTimeImmutable(),
        ])));
    }

    public function find(string $id): ?StoredJob
    {
        $row = $this->writer->find(self::TABLE, $id);

        return null === $row ? null : $this->hydrate($row);
    }

    /**
     * The next job that is due and not running, oldest first.
     *
     * @param list<string> $skip ids already tried in this run
     */
    public function nextDue(DateTimeImmutable $now, array $skip = []): ?StoredJob
    {
        $query = $this->due($now)->select('*')->orderBy('available_at')->addOrderBy('id')->setMaxResults(1);

        if ([] !== $skip) {
            $query->andWhere($query->expr()->notIn('id', array_map(fn(string $id): string => $this->connection->quote($id), $skip)));
        }

        $row = $query->executeQuery()->fetchAssociative();

        return false === $row ? null : $this->hydrate($row);
    }

    public function countDue(DateTimeImmutable $now): int
    {
        return self::number($this->due($now)->select('COUNT(*)')->executeQuery()->fetchOne());
    }

    public function countFailed(): int
    {
        return self::number($this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(self::TABLE)
            ->where('failed_at IS NOT NULL')
            ->executeQuery()
            ->fetchOne());
    }

    /**
     * Locks a job for one worker and counts the attempt. False if another
     * worker was faster.
     */
    public function claim(StoredJob $job, DateTimeImmutable $now, DateTimeImmutable $until): bool
    {
        return $this->journal->trusted(fn(): bool => 1 === (int) $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('locked_until', ':until')
            ->set('attempts', ':attempts')
            ->where('id = :id')
            ->andWhere('attempts = :before')
            ->andWhere('(locked_until IS NULL OR locked_until < :now)')
            ->setParameter('until', $until->format(Values::DATE_FORMAT))
            ->setParameter('attempts', $job->attempts + 1)
            ->setParameter('before', $job->attempts)
            ->setParameter('id', $job->id)
            ->setParameter('now', $now->format(Values::DATE_FORMAT))
            ->executeStatement());
    }

    public function retryAt(string $id, string $error, DateTimeImmutable $availableAt): void
    {
        $this->journal->trusted(fn(): array => $this->writer->update(self::TABLE, $id, [
            'availableAt' => $availableAt,
            'lastError' => $error,
            'lockedUntil' => null,
        ]));
    }

    public function fail(string $id, string $error, DateTimeImmutable $now): void
    {
        $this->journal->trusted(fn(): array => $this->writer->update(self::TABLE, $id, [
            'failedAt' => $now,
            'lastError' => $error,
            'lockedUntil' => null,
        ]));
    }

    /**
     * Puts a failed job back in the queue with all its attempts.
     */
    public function restart(string $id, DateTimeImmutable $now): void
    {
        $this->journal->trusted(fn(): array => $this->writer->update(self::TABLE, $id, [
            'failedAt' => null,
            'attempts' => 0,
            'availableAt' => $now,
            'lockedUntil' => null,
        ]));
    }

    public function delete(string $id): bool
    {
        return $this->journal->trusted(fn(): bool => $this->writer->delete(self::TABLE, $id));
    }

    /**
     * @return int how many were removed
     */
    public function pruneFailed(DateTimeImmutable $before): int
    {
        return $this->journal->trusted(fn(): int => (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('failed_at IS NOT NULL')
            ->andWhere('failed_at < :before')
            ->setParameter('before', $before->format(Values::DATE_FORMAT))
            ->executeStatement());
    }

    /**
     * Waiting jobs by due date, then failed ones, newest first.
     *
     * @return list<StoredJob>
     */
    public function all(int $limit = 200): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('failed_at IS NULL')
            ->orderBy('available_at')
            ->addOrderBy('id')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $failed = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('failed_at IS NOT NULL')
            ->orderBy('failed_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), [...$rows, ...$failed]);
    }

    private function due(DateTimeImmutable $now): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->from(self::TABLE)
            ->where('failed_at IS NULL')
            ->andWhere('available_at <= :now')
            ->andWhere('(locked_until IS NULL OR locked_until < :now)')
            ->setParameter('now', $now->format(Values::DATE_FORMAT));
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StoredJob
    {
        return EntityMapper::hydrate(StoredJob::class, $row);
    }
}
