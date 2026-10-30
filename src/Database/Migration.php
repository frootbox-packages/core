<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\Migrations\AbstractMigration;
use Frootbox\Core\Database\Schema\Blueprint;
use Frootbox\Core\Database\Schema\SchemaException;

/**
 * Base class for all Frootbox migrations.
 *
 *     public function up(Schema $schema): void
 *     {
 *         $this->createTable('news', function (Blueprint $table): void {
 *             $table->id();
 *             $table->string('title');
 *             $table->timestamps();
 *         });
 *     }
 *
 * The SQL comes from Doctrine DBAL and fits SQLite, MySQL and MariaDB.
 * For anything the helpers do not cover, addSql() and the DBAL schema API
 * stay available.
 */
abstract class Migration extends AbstractMigration
{
    /**
     * MySQL and MariaDB end a transaction with every schema change, so
     * migrations run in a transaction only on the other databases.
     */
    #[\Override]
    public function isTransactional(): bool
    {
        return !$this->platform instanceof AbstractMySQLPlatform;
    }

    /**
     * @param \Closure(Blueprint): void $define
     */
    protected function createTable(string $name, \Closure $define): void
    {
        $blueprint = new Blueprint($this->tableName($name));
        $define($blueprint);

        foreach ($this->platform->getCreateTableSQL($blueprint->toTable()) as $sql) {
            $this->addSql($sql);
        }
    }

    /**
     * Changes an existing table: add, rename or drop columns and indexes.
     *
     * @param \Closure(Blueprint): void $define
     */
    protected function alterTable(string $name, \Closure $define): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $current = $schemaManager->introspectTableByUnquotedName($this->tableName($name));

        $blueprint = new Blueprint($this->tableName($name));
        $define($blueprint);

        $diff = $schemaManager->createComparator()->compareTables($current, $blueprint->applyTo($current));

        foreach ($this->platform->getAlterTableSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    protected function renameTable(string $from, string $to): void
    {
        $this->addSql($this->platform->getRenameTableSQL($this->tableName($from), $this->tableName($to)));
    }

    protected function dropTable(string $name): void
    {
        $this->addSql($this->platform->getDropTableSQL($this->tableName($name)));
    }

    /**
     * @return non-empty-string
     */
    private function tableName(string $name): string
    {
        if ('' === $name) {
            throw new SchemaException('Empty table name.');
        }

        return $name;
    }
}
