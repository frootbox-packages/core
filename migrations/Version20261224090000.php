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
 * Signing in through OpenID Connect (decision 0322): the link of a user
 * to an account at a provider, the provider of a session, and the
 * highest role the hub may give.
 */
final class Version20261224090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates user_identities, adds access_tokens.provider, access_tokens.provider_session and hub_connections.login_max_role';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('user_identities', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->string('provider', 50);
            $table->string('issuer', 255);
            $table->string('subject', 255);
            // Managed by the provider: role and lock come from there.
            $table->boolean('managed')->default(false);
            $table->dateTime('created_at');
            $table->dateTime('last_used_at')->nullable();
            $table->unique(['issuer', 'subject']);
            $table->unique(['user_id', 'provider']);
        });
        $this->alterTable('access_tokens', function (Blueprint $table): void {
            $table->string('provider', 50)->nullable();
            $table->string('provider_session', 255)->nullable();
            $table->index(['provider', 'provider_session'], 'access_tokens_provider_session');
        });
        $this->alterTable('hub_connections', function (Blueprint $table): void {
            $table->string('login_max_role', 50)->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('hub_connections', function (Blueprint $table): void {
            $table->dropColumn('login_max_role');
        });
        $this->alterTable('access_tokens', function (Blueprint $table): void {
            $table->dropIndex('access_tokens_provider_session');
            $table->dropColumn('provider');
            $table->dropColumn('provider_session');
        });
        $this->dropTable('user_identities');
    }
}
