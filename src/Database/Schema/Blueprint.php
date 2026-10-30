<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database\Schema;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Describes a table in a migration, translated to Doctrine DBAL.
 *
 * The method names follow the widely known schema builder style, so that
 * people and AI tools can write migrations without learning DBAL:
 *
 *     $table->id();
 *     $table->string('title');
 *     $table->json('content')->nullable();
 *     $table->timestamps();
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<non-empty-string> */
    private array $primary = [];

    /** @var list<array{list<non-empty-string>, IndexType, ?string}> */
    private array $indexes = [];

    /** @var list<non-empty-string> */
    private array $dropColumns = [];

    /** @var array<non-empty-string, non-empty-string> */
    private array $renameColumns = [];

    /** @var list<non-empty-string> */
    private array $dropIndexes = [];

    /**
     * @param non-empty-string $table
     */
    public function __construct(public readonly string $table) {}

    /**
     * Auto-incrementing big integer as primary key.
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        $column = $this->add($name, Types::BIGINT)->unsigned()->autoIncrement();
        $this->primary = [$column->name];

        return $column;
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add($name, Types::STRING, length: $length);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->add($name, Types::TEXT);
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->add($name, Types::INTEGER);
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->add($name, Types::BIGINT);
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->add($name, Types::BOOLEAN);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->add($name, Types::DECIMAL, precision: $precision, scale: $scale);
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->add($name, Types::DATE_IMMUTABLE);
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->add($name, Types::DATETIME_IMMUTABLE);
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->add($name, Types::JSON);
    }

    /**
     * Nullable created_at and updated_at.
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    /**
     * Primary key over one or more columns, e.g. for link tables.
     */
    public function primary(string ...$columns): void
    {
        $this->primary = $this->names($columns);
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = [$this->names((array) $columns), IndexType::REGULAR, $name];
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = [$this->names((array) $columns), IndexType::UNIQUE, $name];
    }

    public function dropColumn(string ...$columns): void
    {
        array_push($this->dropColumns, ...$this->names($columns));
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->renameColumns[$this->name($from)] = $this->name($to);
    }

    public function dropIndex(string $name): void
    {
        $this->dropIndexes[] = $this->name($name);
    }

    /**
     * The new table for createTable().
     */
    public function toTable(): Table
    {
        if ([] !== $this->dropColumns || [] !== $this->renameColumns || [] !== $this->dropIndexes) {
            throw new SchemaException(\sprintf('Table "%s" does not exist yet, so nothing can be dropped or renamed.', $this->table));
        }

        $editor = Table::editor()->setUnquotedName($this->table);

        foreach ($this->columns as $column) {
            $editor->addColumn($column->toColumn());
        }

        if ([] !== $this->primary) {
            $editor->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$this->primary)->create());
        }

        foreach ($this->collectIndexes() as $index) {
            $editor->addIndex($index);
        }

        return $editor->create();
    }

    /**
     * The changed table for alterTable(), based on the existing one.
     */
    public function applyTo(Table $table): Table
    {
        if ([] !== $this->primary) {
            throw new SchemaException(\sprintf('The primary key of table "%s" cannot be changed with alterTable().', $this->table));
        }

        $editor = $table->edit();

        foreach ($this->dropIndexes as $name) {
            $editor->dropIndexByUnquotedName($name);
        }

        foreach ($this->dropColumns as $name) {
            $editor->dropColumnByUnquotedName($name);
        }

        foreach ($this->renameColumns as $from => $to) {
            $editor->renameColumnByUnquotedName($from, $to);
        }

        foreach ($this->columns as $column) {
            $editor->addColumn($column->toColumn());
        }

        foreach ($this->collectIndexes() as $index) {
            $editor->addIndex($index);
        }

        return $editor->create();
    }

    private function add(string $name, string $type, ?int $length = null, ?int $precision = null, int $scale = 0): ColumnDefinition
    {
        $column = new ColumnDefinition($this->name($name), $type, $length, $precision, $scale);
        $this->columns[] = $column;

        return $column;
    }

    /**
     * @return list<Index>
     */
    private function collectIndexes(): array
    {
        $indexes = $this->indexes;

        foreach ($this->columns as $column) {
            if ($column->isUnique()) {
                $indexes[] = [[$column->name], IndexType::UNIQUE, null];
            }

            if ($column->isIndexed()) {
                $indexes[] = [[$column->name], IndexType::REGULAR, null];
            }
        }

        $result = [];

        foreach ($indexes as [$columns, $type, $name]) {
            $result[] = Index::editor()
                ->setUnquotedName($this->name($name ?? $this->indexName($columns, $type)))
                ->setType($type)
                ->setUnquotedColumnNames(...$columns)
                ->create();
        }

        return $result;
    }

    /**
     * Name like news_slug_unique. Long names are shortened with a hash,
     * because MySQL allows at most 64 characters.
     *
     * @param list<non-empty-string> $columns
     */
    private function indexName(array $columns, IndexType $type): string
    {
        $name = $this->table . '_' . implode('_', $columns) . '_' . (IndexType::UNIQUE === $type ? 'unique' : 'index');

        return \strlen($name) <= 64 ? $name : substr($name, 0, 55) . '_' . substr(md5($name), 0, 8);
    }

    /**
     * @param array<string> $names
     *
     * @return list<non-empty-string>
     */
    private function names(array $names): array
    {
        if ([] === $names) {
            throw new SchemaException(\sprintf('At least one column is required for table "%s".', $this->table));
        }

        return array_values(array_map($this->name(...), $names));
    }

    /**
     * @return non-empty-string
     */
    private function name(string $name): string
    {
        if ('' === $name) {
            throw new SchemaException(\sprintf('Empty name in table "%s".', $this->table));
        }

        return $name;
    }
}
