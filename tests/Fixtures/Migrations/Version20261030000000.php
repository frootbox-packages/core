<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Database\Migration;
use Frootbox\Database\Schema\Blueprint;

/**
 * Test migration: creates a small table on every supported database.
 */
final class Version20261030000000 extends Migration
{
    public const string TABLE = 'test_items';

    public function getDescription(): string
    {
        return 'Creates the test table test_items';
    }

    public function up(Schema $schema): void
    {
        $this->createTable(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->json('content')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable(self::TABLE);
    }
}
