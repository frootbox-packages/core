<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Database\Query\Criteria;

/**
 * Reads and writes the rows of one table as entities. A subclass names
 * its table and entity class and declares the entity type for PHPStan
 * in its PHPDoc (extends Repository<Page>):
 *
 *     final class Pages extends Repository
 *     {
 *         protected function table(): string
 *         {
 *             return 'pages';
 *         }
 *
 *         protected function entity(): string
 *         {
 *             return Page::class;
 *         }
 *     }
 *
 *     $pages->fetch(['where' => ['parentId' => 5], 'order' => 'title', 'limit' => 10]);
 *
 * Writing (insert, update, delete) only works inside an action; every
 * change is recorded so the action can be undone. Columns created_at and
 * updated_at are filled automatically when the entity has them.
 *
 * @template T of Entity
 */
abstract class Repository
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly TableWriter $writer,
    ) {}

    abstract protected function table(): string;

    /**
     * @return class-string<T>
     */
    abstract protected function entity(): string;

    /**
     * @param array{where?: array<int|string, mixed>, order?: string|array<int|string, string>, limit?: int, offset?: int} $options
     *
     * @return Result<T>
     */
    public function fetch(array $options = []): Result
    {
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->connection->quoteSingleIdentifier($this->table()));
        new Criteria($query, $this->connection->getDatabasePlatform())->apply($options);

        return new Result(array_map($this->hydrate(...), $query->executeQuery()->fetchAllAssociative()));
    }

    /**
     * @param array{where?: array<int|string, mixed>, order?: string|array<int|string, string>, offset?: int} $options
     *
     * @return T|null
     */
    public function fetchOne(array $options = []): ?Entity
    {
        return $this->fetch(['limit' => 1] + $options)->first();
    }

    /**
     * @return T|null
     */
    public function fetchById(int|string $id): ?Entity
    {
        return $this->fetchOne(['where' => ['id' => $id]]);
    }

    /**
     * @return T
     */
    public function getById(int|string $id): Entity
    {
        return $this->fetchById($id) ?? throw EntityNotFoundException::for($this->table(), $id);
    }

    /**
     * @param array<int|string, mixed> $where
     */
    public function count(array $where = []): int
    {
        $query = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->connection->quoteSingleIdentifier($this->table()));
        new Criteria($query, $this->connection->getDatabasePlatform())->where($where);

        $count = $query->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param array{where?: array<int|string, mixed>, order?: string|array<int|string, string>} $options
     *
     * @return Paginated<T>
     */
    public function paginate(array $options = [], int $page = 1, int $perPage = 20): Paginated
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return new Paginated(
            $this->fetch(['limit' => $perPage, 'offset' => ($page - 1) * $perPage] + $options),
            $this->count($options['where'] ?? []),
            $page,
            $perPage,
        );
    }

    /**
     * @param array<string, mixed> $values by property or column name
     *
     * @return T
     */
    public function insert(array $values): Entity
    {
        $now = new DateTimeImmutable();

        foreach (['createdAt' => 'created_at', 'updatedAt' => 'updated_at'] as $property => $column) {
            if ($this->hasColumn($column) && !\array_key_exists($property, $values) && !\array_key_exists($column, $values)) {
                $values[$column] = $now;
            }
        }

        return $this->hydrate($this->writer->insert($this->table(), $values));
    }

    /**
     * @param array<string, mixed> $values by property or column name
     *
     * @return T
     */
    public function update(int|string $id, array $values): Entity
    {
        if ($this->hasColumn('updated_at') && !\array_key_exists('updatedAt', $values) && !\array_key_exists('updated_at', $values)) {
            $values['updated_at'] = new DateTimeImmutable();
        }

        return $this->hydrate($this->writer->update($this->table(), $id, $values));
    }

    /**
     * @return bool false if there was no such row
     */
    public function delete(int|string $id): bool
    {
        return $this->writer->delete($this->table(), $id);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return T
     */
    protected function hydrate(array $row): Entity
    {
        return EntityMapper::hydrate($this->entity(), $row);
    }

    private function hasColumn(string $column): bool
    {
        return \in_array($column, EntityMapper::columns($this->entity()), true);
    }
}
