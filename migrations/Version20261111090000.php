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
 * The language of the backend per user, empty for automatic (decision 0161).
 */
final class Version20261111090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds users.language';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('users', function (Blueprint $table): void {
            $table->string('language', 35)->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('users', function (Blueprint $table): void {
            $table->dropColumn('language');
        });
    }
}
