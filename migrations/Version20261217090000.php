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
 * The proofs of consent, kept only while the setting "proof" is on
 * (decision 0264). No address, no browser.
 */
final class Version20261217090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Adds consent_proofs';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('consent_proofs', function (Blueprint $table): void {
            $table->ulid();
            $table->string('consent_id', 64)->index();
            $table->string('version', 20);
            $table->json('choices');
            $table->dateTime('created_at')->index();
        });
    }

    public function down(Schema $schema): void
    {
        $this->dropTable('consent_proofs');
    }
}
