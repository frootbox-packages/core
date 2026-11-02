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
 * The index of the global search (decision 0214). It fills itself on the
 * first search.
 */
final class Version20261116090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates search_index';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('search_index', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 150);
            $table->string('item', 64);
            $table->string('language', 20)->default('');
            $table->string('state', 10);
            $table->string('title');
            $table->text('body');
            $table->text('terms');
            $table->string('permission', 150);
            $table->string('scope_kind', 50)->nullable();
            $table->string('scope_id', 64)->nullable();
            $table->boolean('label')->default(false);
            $table->dateTime('updated_at');
            $table->index(['source', 'item']);
            $table->index(['state', 'updated_at']);
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('search_index');
    }
}
