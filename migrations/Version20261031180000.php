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
 * Current and earlier URLs of targets, manual redirects and the log of
 * requests that found nothing (decision 0105).
 */
final class Version20261031180000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates urls, redirects and not_found';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('urls', function (Blueprint $table): void {
            $table->id();
            $table->string('target', 150)->index();
            $table->string('language', 20);
            $table->ulid('base_page_id')->nullable();
            $table->string('path', 500);
            $table->boolean('current');
            $table->boolean('fallback');
            $table->timestamps();
            $table->index(['language', 'path']);
        });

        $this->createTable('redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 500)->unique();
            $table->string('target', 150)->nullable();
            $table->string('location', 2000)->nullable();
            $table->boolean('permanent');
            $table->timestamps();
        });

        $this->createTable('not_found', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 500)->unique();
            $table->integer('hits');
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('not_found');
        $this->dropTable('redirects');
        $this->dropTable('urls');
    }
}
