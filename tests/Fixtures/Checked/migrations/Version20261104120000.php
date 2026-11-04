<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Checked\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Database\Migration;
use Frootbox\Database\Schema\Blueprint;

/**
 * One table with the name of the package, one without.
 */
final class Version20261104120000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates the tables of acme/checked';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('acme_checked_items', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        $this->createTable('checked_log', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('checked_log');
        $this->dropTable('acme_checked_items');
    }
}
