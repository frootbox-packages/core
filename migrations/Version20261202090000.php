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
 * A description of each page for search engines (decision 0223).
 */
final class Version20261202090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds page_translations.description';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('page_translations', function (Blueprint $table): void {
            $table->string('description', 300)->default('');
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('page_translations', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
}
