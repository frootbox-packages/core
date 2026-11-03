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
 * Whether an AI made or changed a medium (decision 0251).
 */
final class Version20261215090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds media.ai';
    }

    public function up(Schema $schema): void
    {
        $this->alterTable('media', function (Blueprint $table): void {
            $table->string('ai', 10)->default('none');
        });
    }

    public function down(Schema $schema): void
    {
        $this->alterTable('media', function (Blueprint $table): void {
            $table->dropColumn('ai');
        });
    }
}
