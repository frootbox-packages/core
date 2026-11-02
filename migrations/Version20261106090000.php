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
 * The same file only once and where media are used (decision 0146).
 * Media from before have no hash: their original is already processed.
 */
final class Version20261106090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds media.hash and media_usages';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('media', function (Blueprint $table): void {
            // SHA-256 of the uploaded bytes, before any processing.
            $table->string('hash', 64)->nullable()->index();
        });

        $this->createTable('media_usages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('media_id')->index();
            // "page" with the page id, "site" with the package of the theme.
            $table->string('owner_type', 20);
            $table->string('owner_id', 200);
            $table->string('language', 20);
            $table->boolean('published');
            $table->index(['owner_type', 'owner_id']);
            $table->unique(['media_id', 'owner_type', 'owner_id', 'language', 'published']);
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('media_usages');

        $this->alterTable('media', function (Blueprint $table): void {
            $table->dropIndex('media_hash_index');
            $table->dropColumn('hash');
        });
    }
}
