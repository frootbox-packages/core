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
 * The copyright of a medium, e.g. the photographer or the image agency
 * (decision 0203).
 */
final class Version20261113090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds media.copyright';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('media', function (Blueprint $table): void {
            $table->string('copyright', 300)->default('');
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('media', function (Blueprint $table): void {
            $table->dropColumn('copyright');
        });
    }
}
