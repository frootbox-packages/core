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
 * Throttling of the sign-in and "stay signed in" (decision 0115).
 */
final class Version20261101090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates sign_in_failures, adds access_tokens.remember';
    }

    public function up(Schema $schema): void
    {
        // A key is the SHA-256 of "email:…" or "ip:…", never the value itself.
        $this->createTable('sign_in_failures', function (Blueprint $table): void {
            $table->id();
            $table->string('key_hash', 64)->index();
            $table->dateTime('failed_at')->index();
        });

        $this->alterTable('access_tokens', function (Blueprint $table): void {
            $table->boolean('remember')->default(false);
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('access_tokens', function (Blueprint $table): void {
            $table->dropColumn('remember');
        });

        $this->dropTable('sign_in_failures');
    }
}
