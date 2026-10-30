<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Core\Database\Migration;
use Frootbox\Core\Database\Schema\Blueprint;

final class Version20261030130000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates the test table notes';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 100);
            $table->text('body')->nullable();
            $table->integer('priority')->default(0);
            $table->string('status', 20)->default('draft');
            $table->boolean('pinned')->default(false);
            $table->json('tags')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('notes');
    }
}
