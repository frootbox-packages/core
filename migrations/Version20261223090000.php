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
 * Sessions of visitors of the website, e.g. for a cart (decision 0312).
 */
final class Version20261223090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates the table visitor_sessions';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('visitor_sessions', function (Blueprint $table): void {
            // SHA-256 of the cookie value, never the value itself.
            $table->string('id', 64);
            $table->primary('id');
            $table->text('data');
            $table->dateTime('created_at');
            $table->dateTime('seen_at');
            $table->dateTime('expires_at');
            $table->index('expires_at');
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('visitor_sessions');
    }
}
