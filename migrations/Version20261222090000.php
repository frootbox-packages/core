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
 * Token and device in the log of actions, the device at the token too,
 * since tokens are deleted when they expire, and which changes are
 * shortened after they can no longer be undone (decision 0174).
 */
final class Version20261222090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds action_log.token_id, action_log.device, access_tokens.device and action_changes.shortened';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('access_tokens', function (Blueprint $table): void {
            $table->string('device', 100)->nullable();
        });
        $this->alterTable('action_log', function (Blueprint $table): void {
            $table->bigInteger('token_id')->unsigned()->nullable();
            $table->string('device', 100)->nullable();
        });
        $this->alterTable('action_changes', function (Blueprint $table): void {
            $table->boolean('shortened')->default(false);
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('action_changes', function (Blueprint $table): void {
            $table->dropColumn('shortened');
        });
        $this->alterTable('action_log', function (Blueprint $table): void {
            $table->dropColumn('token_id');
            $table->dropColumn('device');
        });
        $this->alterTable('access_tokens', function (Blueprint $table): void {
            $table->dropColumn('device');
        });
    }
}
