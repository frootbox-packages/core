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
 * Further fields at objects (decisions 0234, 0240): the values of the
 * published objects, one row per object. Drafts keep them in revisions.
 */
final class Version20261210090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds object_fields';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('object_fields', function (Blueprint $table): void {
            $table->id();
            $table->string('target_type', 150);
            $table->string('target_id', 64);
            $table->json('fields');
            $table->unique(['target_type', 'target_id']);
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('object_fields');
    }
}
