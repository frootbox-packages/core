<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Core\Database\Migration;
use Frootbox\Core\Database\Schema\Blueprint;

/**
 * Test migration: changes the table of the previous migration.
 */
final class Version20261030000001 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds slug and position to test_items';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable(Version20261030000000::TABLE, function (Blueprint $table): void {
            $table->string('slug', 100)->nullable()->unique();
            $table->integer('position')->default(0)->index();
            $table->renameColumn('content', 'body');
            $table->dropColumn('visible');
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable(Version20261030000000::TABLE, function (Blueprint $table): void {
            $table->dropIndex('test_items_slug_unique');
            $table->dropIndex('test_items_position_index');
            $table->dropColumn('slug', 'position');
            $table->renameColumn('body', 'content');
            $table->boolean('visible')->default(true);
        });
    }
}
