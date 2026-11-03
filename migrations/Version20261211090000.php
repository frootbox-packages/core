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
 * Pages of their own for the categories of a plugin (decision 0243).
 */
final class Version20261211090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds pages.plugin_categories';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('pages', function (Blueprint $table): void {
            $table->boolean('plugin_categories')->default(false);
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('pages', function (Blueprint $table): void {
            $table->dropColumn('plugin_categories');
        });
    }
}
