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
 * Media with folders, texts per language and tags (decision 0126).
 */
final class Version20261102090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds media, media_folders, media_texts and media_tags';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('media_folders', function (Blueprint $table): void {
            $table->ulid();
            $table->ulid('parent_id')->nullable()->index();
            $table->string('name', 200);
            $table->timestamps();
        });

        $this->createTable('media', function (Blueprint $table): void {
            $table->ulid();
            $table->ulid('folder_id')->nullable()->index();
            $table->string('file_name', 255);
            $table->string('type', 50);
            $table->integer('size');
            $table->integer('width');
            $table->integer('height');
            // Thousandths of width and height, 500 is the middle.
            $table->integer('focus_x')->default(500);
            $table->integer('focus_y')->default(500);
            $table->integer('version')->default(1);
            $table->boolean('public')->default(false);
            $table->timestamps();
        });

        $this->createTable('media_texts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('media_id')->index();
            $table->string('language', 20);
            $table->string('alt', 500)->default('');
            $table->string('title', 200)->default('');
            $table->unique(['media_id', 'language']);
        });

        $this->createTable('media_tags', function (Blueprint $table): void {
            $table->id();
            $table->ulid('media_id')->index();
            $table->string('tag', 100)->index();
            $table->unique(['media_id', 'tag']);
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('media_tags');
        $this->dropTable('media_texts');
        $this->dropTable('media');
        $this->dropTable('media_folders');
    }
}
