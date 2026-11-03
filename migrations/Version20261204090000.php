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
 * The search on the website (decision 0228): published entries know the
 * page or object they lead to.
 */
final class Version20261204090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds search_index.target';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('search_index', function (Blueprint $table): void {
            $table->string('target', 150)->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('search_index', function (Blueprint $table): void {
            $table->dropColumn('target');
        });
    }
}
