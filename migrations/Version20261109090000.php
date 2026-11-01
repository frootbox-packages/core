<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Database\Migration;
use Frootbox\Database\Schema\Blueprint;

/**
 * Comments in the preview and the editing mode (decision 0156). The role
 * "editor" gets comment.*.
 */
final class Version20261109090000 extends Migration
{
    private const string RULE = 'comment.*';

    public function getDescription(): string
    {
        return 'Adds comments';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('comments', function (Blueprint $table): void {
            $table->ulid();
            // Null for the first comment of a thread, else that comment.
            $table->ulid('thread_id')->nullable()->index();
            // The place, only on the first comment.
            $table->ulid('page_id')->nullable()->index();
            $table->string('language', 35)->nullable();
            $table->string('path', 2048)->nullable();
            $table->string('block_id', 64)->nullable();
            $table->string('field', 255)->nullable();
            $table->boolean('site');
            $table->string('excerpt', 300)->nullable();
            $table->integer('viewport')->nullable();
            $table->text('body');
            $table->string('author_name', 80);
            $table->bigInteger('user_id')->unsigned()->nullable();
            // The link a guest wrote with; emptied when the link is deleted.
            $table->ulid('preview_link_id')->nullable()->index();
            $table->string('status', 16);
            $table->dateTime('resolved_at')->nullable();
            $table->bigInteger('resolved_by')->unsigned()->nullable();
            $table->timestamps();
        });

        $this->change(static fn(array $rules): array => \in_array(self::RULE, $rules, true) ? $rules : [...$rules, self::RULE]);
    }

    public function down(Schema $schema): void
    {
        $this->change(static fn(array $rules): array => array_values(array_filter($rules, static fn(mixed $rule): bool => self::RULE !== $rule)));
        $this->dropTable('comments');
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
