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
 * Settings of extensions with encrypted secrets and the queue of jobs
 * (decision 0221).
 */
final class Version20261117090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates settings and jobs';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('package', 100);
            $table->string('name', 100);
            $table->text('value')->nullable();
            $table->text('secret')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['package', 'name']);
        });

        $this->createTable('jobs', function (Blueprint $table): void {
            $table->ulid();
            $table->string('name', 150);
            $table->text('input');
            $table->string('actor_type', 20);
            $table->integer('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->dateTime('available_at');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts');
            $table->text('last_error')->nullable();
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['failed_at', 'available_at']);
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('jobs');
        $this->dropTable('settings');
    }
}
