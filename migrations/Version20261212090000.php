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
 * The names of the default spaces of plugins (decision 0246), e.g.
 * "Referenzen" for the collections. Without a row it is named after
 * the plugin.
 */
final class Version20261212090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds space_defaults';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('space_defaults', function (Blueprint $table): void {
            $table->ulid();
            $table->string('type', 150)->unique();
            $table->string('title', 100);
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('space_defaults');
    }
}
