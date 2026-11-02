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
 * Comments on the design page and preview links that open it
 * (decision 0210).
 */
final class Version20261114090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds comments.design and preview_links.design';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('comments', function (Blueprint $table): void {
            $table->string('design', 255)->nullable();
        });
        $this->alterTable('preview_links', function (Blueprint $table): void {
            $table->boolean('design')->default(false);
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('comments', function (Blueprint $table): void {
            $table->dropColumn('design');
        });
        $this->alterTable('preview_links', function (Blueprint $table): void {
            $table->dropColumn('design');
        });
    }
}
