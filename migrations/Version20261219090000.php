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
 * Service accounts (decisions 0180, 0285): users of the kind "service"
 * sign in only with API tokens and have no email.
 */
final class Version20261219090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds users.kind and lets users.email be empty for service accounts';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('users', function (Blueprint $table): void {
            $table->string('kind', 20)->default('person');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM users WHERE kind = 'service'");
        $this->alterTable('users', function (Blueprint $table): void {
            $table->dropColumn('kind');
            $table->string('email')->change();
        });
    }
}
