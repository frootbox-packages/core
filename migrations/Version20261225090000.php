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
 * A password for a page and maybe its subpages (decision 0358), in a
 * table of its own, so pages are delivered without protection until it
 * is migrated.
 */
final class Version20261225090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Creates page_protections';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('page_protections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('page_id');
            // "page" or "branch", as for the lock of a page.
            $table->string('reach', 10);
            $table->string('password_hash', 255);
            // New with every password, so browsers let in before are locked out.
            $table->string('generation', 32);
            $table->timestamps();
            $table->unique('page_id');
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('page_protections');
    }
}
