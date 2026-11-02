<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Frootbox\Database\EntityMapper;
use Psr\Log\LoggerInterface;

/**
 * Counts requests that found nothing, per path without query (decision
 * 0105). No IP, no referrer, no user agent. At most MAX paths; beyond
 * that the rarest and oldest go.
 *
 * It is a log, not content: it is written while delivering, directly and
 * not through an action, so a busy 404 never blocks a page.
 */
final readonly class NotFoundLog
{
    public const string TABLE = 'not_found';

    public const int MAX = 1000;

    public const int MAX_PATH_LENGTH = 500;

    private const array SKIPPED = ['/assets/', '/api/'];

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {}

    public function record(string $path): void
    {
        if (\strlen($path) > self::MAX_PATH_LENGTH || array_any(self::SKIPPED, static fn(string $prefix): bool => str_starts_with($path . '/', $prefix))) {
            return;
        }

        $now = new DateTimeImmutable()->format('Y-m-d H:i:s');

        try {
            $updated = $this->connection->executeStatement(
                \sprintf('UPDATE %s SET hits = hits + 1, last_seen_at = ? WHERE path = ?', $this->table()),
                [$now, $path],
            );

            if (0 === $updated) {
                try {
                    $this->connection->insert($this->table(), ['path' => $path, 'hits' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now]);
                } catch (UniqueConstraintViolationException) {
                    // Another request was faster.
                    return;
                }

                $this->prune();
            }
        } catch (DbalException $error) {
            $this->logger->warning('Could not log the missing page {path}: {message}', ['path' => $path, 'message' => $error->getMessage()]);
        }
    }

    /**
     * The most frequent paths first.
     *
     * @return list<NotFoundEntry>
     */
    public function entries(int $limit = 100): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table())
            ->orderBy('hits', 'DESC')
            ->addOrderBy('last_seen_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): NotFoundEntry => EntityMapper::hydrate(NotFoundEntry::class, $row), $rows);
    }

    public function forget(string $path): void
    {
        $this->connection->delete($this->table(), ['path' => $path]);
    }

    public function clear(): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM ' . $this->table());
    }

    private function prune(): void
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->table());
        $excess = (is_numeric($count) ? (int) $count : 0) - self::MAX;

        if ($excess <= 0) {
            return;
        }

        $ids = $this->connection->createQueryBuilder()
            ->select('id')
            ->from($this->table())
            ->orderBy('hits', 'ASC')
            ->addOrderBy('last_seen_at', 'ASC')
            ->setMaxResults($excess)
            ->executeQuery()
            ->fetchFirstColumn();

        if ([] !== $ids) {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE id IN (%s)', $this->table(), implode(', ', array_fill(0, \count($ids), '?'))),
                $ids,
            );
        }
    }

    private function table(): string
    {
        return $this->connection->quoteSingleIdentifier(self::TABLE);
    }
}
