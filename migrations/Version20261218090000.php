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
 * A title of its own for search engines on each page (decision 0266).
 */
final class Version20261218090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds page_translations.seo_title';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('page_translations', function (Blueprint $table): void {
            $table->string('seo_title', 120)->default('');
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('page_translations', function (Blueprint $table): void {
            $table->dropColumn('seo_title');
        });
    }
}
