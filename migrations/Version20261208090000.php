<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Database\Migration;
use Frootbox\Database\Schema\Blueprint;

/**
 * Categories for all objects (decision 0233): groups an admin creates,
 * the categories of all groups as a tree and the categories of the
 * published objects. The standard role "editor" gets category.*.
 */
final class Version20261208090000 extends Migration
{
    private const string RULE = 'category.*';

    public function getDescription(): string
    {
        return 'Adds category_groups, categories and category_links';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('category_groups', function (Blueprint $table): void {
            $table->ulid();
            $table->string('title', 100);
            $table->json('types');
            $table->timestamps();
        });

        $this->createTable('categories', function (Blueprint $table): void {
            $table->ulid();
            $table->string('group_key', 150)->index();
            $table->ulid('parent_id')->nullable()->index();
            $table->integer('position');
            $table->json('translations');
            $table->timestamps();
        });

        $this->createTable('category_links', function (Blueprint $table): void {
            $table->id();
            $table->ulid('category_id')->index();
            $table->string('target_type', 150);
            $table->string('target_id', 64);
            $table->index(['target_type', 'target_id']);
            $table->unique(['category_id', 'target_type', 'target_id']);
        });

        $this->change(static fn(array $rules): array => \in_array(self::RULE, $rules, true) || \in_array('*', $rules, true) ? $rules : [...$rules, self::RULE]);
    }

    public function down(Schema $schema): void
    {
        $this->change(static fn(array $rules): array => array_values(array_filter($rules, static fn(mixed $rule): bool => self::RULE !== $rule)));
        $this->dropTable('category_links');
        $this->dropTable('categories');
        $this->dropTable('category_groups');
    }

    /**
     * @param callable(list<mixed>): list<mixed> $change
     */
    private function change(callable $change): void
    {
        $rules = $this->connection->fetchOne('SELECT permissions FROM roles WHERE name = ?', ['editor']);

        if (!\is_string($rules)) {
            return;
        }

        $rules = json_decode($rules, true, flags: \JSON_THROW_ON_ERROR);

        if (\is_array($rules) && array_is_list($rules)) {
            $this->addSql('UPDATE roles SET permissions = ? WHERE name = ?', [json_encode($change($rules), \JSON_THROW_ON_ERROR), 'editor']);
        }
    }
}
