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
 * Comments at a point of their place and on landmarks of the page
 * (decision 0211).
 */
final class Version20261115090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds comments.landmark, point_x and point_y';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('comments', function (Blueprint $table): void {
            $table->string('landmark', 255)->nullable();
            $table->integer('point_x')->nullable();
            $table->integer('point_y')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('comments', function (Blueprint $table): void {
            $table->dropColumn('landmark', 'point_x', 'point_y');
        });
    }
}
