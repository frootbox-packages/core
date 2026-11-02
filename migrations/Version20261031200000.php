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
 * The published navigations by name and language (decision 0106).
 */
final class Version20261031200000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates navigations';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('navigations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64);
            $table->string('language', 20);
            $table->json('items');
            $table->timestamps();
            $table->unique(['name', 'language']);
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('navigations');
    }
}
