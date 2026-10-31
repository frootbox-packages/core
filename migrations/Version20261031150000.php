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
 * Pages with their translations, and the revisions of all entities
 * (decisions 0094, 0099 and 0101).
 */
final class Version20261031150000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates pages, page_translations and revisions';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('pages', function (Blueprint $table): void {
            $table->ulid();
            $table->ulid('parent_id')->nullable()->index();
            $table->string('position', 100);
            $table->string('layout');
            $table->string('variant', 100)->nullable();
            $table->timestamps();
        });

        $this->createTable('page_translations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('page_id');
            $table->string('language', 20);
            $table->string('title');
            $table->string('slug', 100);
            $table->json('content');
            $table->timestamps();
            $table->unique(['page_id', 'language']);
        });

        $this->createTable('revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 150);
            $table->string('entity_id', 64);
            $table->string('status', 20);
            $table->integer('version')->nullable();
            $table->json('data');
            $table->dateTime('published_at')->nullable();
            $table->dateTime('publish_at')->nullable()->index();
            $table->dateTime('unpublish_at')->nullable()->index();
            $table->bigInteger('author_id')->unsigned()->nullable();
            $table->string('channel', 20)->nullable();
            $table->bigInteger('action_entry_id')->unsigned()->nullable();
            $table->timestamps();
            $table->index(['type', 'entity_id', 'status']);
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('revisions');
        $this->dropTable('page_translations');
        $this->dropTable('pages');
    }
}
