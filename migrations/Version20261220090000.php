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
 * The connection of the website to the hub, frootbox.de (decisions 0284,
 * 0286): at most one row with the key pair of the website.
 */
final class Version20261220090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates hub_connections';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('hub_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('hub_url');
            $table->string('public_key', 100);
            $table->text('secret_key');
            $table->text('hub_keys');
            $table->string('request_id', 100)->nullable();
            $table->dateTime('request_expires_at')->nullable();
            $table->string('connection_id', 100)->nullable();
            $table->string('organization')->nullable();
            $table->boolean('status')->default(false);
            $table->boolean('packages')->default(false);
            $table->boolean('login')->default(false);
            $table->integer('user_id')->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('hub_connections');
    }
}
