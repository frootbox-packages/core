<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Core\Database\Migration;
use Frootbox\Core\Database\Schema\Blueprint;

/**
 * The action log and the recorded changes for undoing actions.
 */
final class Version20261030120000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates action_log and action_changes';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('action_log', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('parent_id')->unsigned()->nullable()->index();
            $table->string('action', 100)->index();
            $table->string('channel', 20);
            $table->string('actor_type', 20);
            $table->bigInteger('actor_id')->unsigned()->nullable();
            $table->string('actor_name')->nullable();
            $table->json('input')->nullable();
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->boolean('undoable')->default(false);
            $table->bigInteger('undone_by')->unsigned()->nullable();
            $table->integer('duration_ms')->unsigned()->default(0);
            $table->dateTime('created_at')->index();
            $table->index(['actor_type', 'actor_id']);
        });

        $this->createTable('action_changes', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('entry_id')->unsigned()->index();
            $table->string('kind', 20);
            $table->string('target', 100);
            $table->string('row_id', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->index(['target', 'row_id']);
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('action_changes');
        $this->dropTable('action_log');
    }
}
