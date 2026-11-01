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
 * Preview links for customers without an account (decisions 0152, 0154)
 * and the password of the website (decision 0153). The role "editor"
 * gets preview.*; protecting the website stays with administrators.
 */
final class Version20261108090000 extends Migration
{
    private const string RULE = 'preview.*';

    public function getDescription(): string
    {
        return 'Adds preview_links and site_protection';
    }

    public function up(Schema $schema): void
    {
        $this->createTable('preview_links', function (Blueprint $table): void {
            $table->ulid();
            // SHA-256 of the token, to look it up; the token itself only encrypted.
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->string('note', 200)->nullable();
            // The page the link opens, null for the start page.
            $table->ulid('page_id')->nullable();
            $table->bigInteger('created_by')->unsigned()->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('first_opened_at')->nullable();
            $table->dateTime('last_opened_at')->nullable();
            $table->integer('opens');
            $table->timestamps();
        });

        $this->createTable('site_protection', function (Blueprint $table): void {
            // At most one row: the protection is on while it exists.
            $table->id();
            $table->string('password_hash', 255);
            // New with every password, so browsers let in before are locked out.
            $table->string('generation', 32);
            $table->timestamps();
        });

        $this->change(static fn(array $rules): array => \in_array(self::RULE, $rules, true) ? $rules : [...$rules, self::RULE]);
    }

    public function down(Schema $schema): void
    {
        $this->change(static fn(array $rules): array => array_values(array_filter($rules, static fn(mixed $rule): bool => self::RULE !== $rule)));
        $this->dropTable('site_protection');
        $this->dropTable('preview_links');
    }

    /**
     * @param callable(list<mixed>): list<mixed> $change
     */
    private function change(callable $change): void
    {
        $rules = $this->connection->fetchOne('SELECT permissions FROM roles WHERE name = ?', ['editor']);

        if (!\is_string($rules)) {
            return;
        }

        $rules = json_decode($rules, true, flags: \JSON_THROW_ON_ERROR);

        if (\is_array($rules) && array_is_list($rules)) {
            $this->addSql('UPDATE roles SET permissions = ? WHERE name = ?', [json_encode($change($rules), \JSON_THROW_ON_ERROR), 'editor']);
        }
    }
}
