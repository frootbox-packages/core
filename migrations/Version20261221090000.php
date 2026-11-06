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
 * What each request to the AI used and cost (decision 0294): one row per
 * model that worked on it, the cost in millionths of a dollar.
 */
final class Version20261221090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates ai_usage';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('ai_usage', function (Blueprint $table): void {
            $table->ulid();
            $table->dateTime('created_at');
            $table->integer('user_id')->nullable();
            $table->string('actor');
            $table->string('feature', 100);
            $table->string('channel', 20);
            $table->string('provider', 100);
            $table->string('model', 100);
            $table->integer('input_tokens');
            $table->integer('output_tokens');
            $table->integer('cache_read_tokens');
            $table->integer('cache_write_tokens');
            $table->bigInteger('cost');
            $table->index('created_at');
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('ai_usage');
    }
}
