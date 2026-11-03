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
 * Tags for all objects (decision 0233): one stock with a fixed key and
 * a label per language, the tags of the published objects, and the key
 * of a preset at a category. The standard role "editor" gets tag.*.
 */
final class Version20261209090000 extends Migration
{
    private const string RULE = 'tag.*';

    public function getDescription(): string
    {
        return 'Adds tags and tag_links, and categories.preset';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('tags', function (Blueprint $table): void {
            $table->ulid();
            $table->string('key', 100)->unique();
            $table->json('labels');
            $table->boolean('internal')->default(false);
            $table->ulid('merged_into')->nullable()->index();
            $table->timestamps();
        });

        $this->createTable('tag_links', function (Blueprint $table): void {
            $table->id();
            $table->ulid('tag_id')->index();
            $table->string('target_type', 150);
            $table->string('target_id', 64);
            $table->index(['target_type', 'target_id']);
            $table->unique(['tag_id', 'target_type', 'target_id']);
        });

        $this->alterTable('categories', function (Blueprint $table): void {
            $table->string('preset', 100)->nullable();
        });

        $this->change(static fn(array $rules): array => \in_array(self::RULE, $rules, true) || \in_array('*', $rules, true) ? $rules : [...$rules, self::RULE]);
    }

    public function down(Schema $schema): void
    {
        $this->change(static fn(array $rules): array => array_values(array_filter($rules, static fn(mixed $rule): bool => self::RULE !== $rule)));
        $this->alterTable('categories', function (Blueprint $table): void {
            $table->dropColumn('preset');
        });
        $this->dropTable('tag_links');
        $this->dropTable('tags');
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
