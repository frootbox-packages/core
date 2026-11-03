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
 * Spaces of plugins and the space a page shows (decision 0232). The
 * default space of each type has no row.
 */
final class Version20261207090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds spaces and pages.plugin_type, pages.plugin_space and pages.plugin_details';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('spaces', function (Blueprint $table): void {
            $table->ulid();
            $table->string('type', 150)->index();
            $table->string('title', 100);
            $table->timestamps();
        });

        $this->alterTable('pages', function (Blueprint $table): void {
            $table->string('plugin_type', 150)->nullable();
            $table->string('plugin_space', 64)->nullable();
            $table->boolean('plugin_details')->default(false);
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('pages', function (Blueprint $table): void {
            $table->dropColumn('plugin_type', 'plugin_space', 'plugin_details');
        });
        $this->dropTable('spaces');
    }
}
