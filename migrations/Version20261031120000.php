<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Database\Migration;
use Frootbox\Database\Schema\Blueprint;

/**
 * Users, roles and their sessions and API tokens, with three standard roles.
 */
final class Version20261031120000 extends Migration
{
    /**
     * Standard roles: name => [title, level, permission rules]. A higher
     * level manages the lower ones; technical actions live under system.*
     * and extension.*, which only the superuser has.
     */
    private const array ROLES = [
        'superuser' => ['Superuser', 100, ['*']],
        'admin' => ['Administrator', 50, ['*', '-system.*', '-extension.*']],
        'editor' => ['Editor', 10, ['page.*', 'media.*', 'navigation.*', 'action.undo']],
    ];

    public function getDescription(): string
    {
        return 'Creates users, roles, role_assignments and access_tokens';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('password')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $this->createTable('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('title');
            $table->integer('level')->default(0);
            $table->json('permissions');
            $table->timestamps();
        });

        $this->createTable('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->bigInteger('role_id')->unsigned()->index();
            $table->string('scope', 100)->nullable();
            $table->dateTime('created_at');
        });

        $this->createTable('access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->string('type', 20);
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('created_at');
        });

        $now = date('Y-m-d H:i:s');

        foreach (self::ROLES as $name => [$title, $level, $permissions]) {
            $this->addSql(
                'INSERT INTO roles (name, title, level, permissions, created_at) VALUES (?, ?, ?, ?, ?)',
                [$name, $title, $level, json_encode($permissions, \JSON_THROW_ON_ERROR), $now],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('access_tokens');
        $this->dropTable('role_assignments');
        $this->dropTable('roles');
        $this->dropTable('users');
    }
}
