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
 * Locking pages and leaving them out of searches (decision 0117).
 */
final class Version20261101100000 extends Migration
{
    private const array COLUMNS = ['locked', 'exclude_from_search_engines', 'exclude_from_site_search'];

    public function getDescription(): string
    {
        return 'Adds pages.locked, pages.exclude_from_search_engines and pages.exclude_from_site_search';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('pages', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->string($column, 10)->default('none');
            }
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('pages', function (Blueprint $table): void {
            $table->dropColumn(...self::COLUMNS);
        });
    }
}
