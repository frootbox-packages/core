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
 * Each file of a medium keeps its own original (decision 0147): the first
 * one lies in storage/media/<id>, a replacing one under its own name.
 */
final class Version20261107090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds media.file';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('media', function (Blueprint $table): void {
            // The name of the original below storage/media/, null for the id.
            $table->string('file', 80)->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('media', function (Blueprint $table): void {
            $table->dropColumn('file');
        });
    }
}
