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
 * Videos in the media library (decision 0177): the codec of a medium and
 * further sources of a video in other formats or codecs.
 */
final class Version20261112090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds media.codec and media_sources';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('media', function (Blueprint $table): void {
            $table->string('codec', 100)->default('');
        });

        $this->createTable('media_sources', function (Blueprint $table): void {
            $table->ulid();
            $table->ulid('media_id')->index();
            $table->string('file', 100);
            $table->string('type', 50);
            $table->string('codec', 100)->default('');
            $table->integer('size');
            $table->integer('width');
            $table->integer('height');
            $table->string('hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('media_sources');
        $this->alterTable('media', function (Blueprint $table): void {
            $table->dropColumn('codec');
        });
    }
}
