<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Database\Migration;

/**
 * Editors no longer change the design (decision 0138): it changes the
 * whole website, so the standard role "editor" loses design.* again.
 * Administrators keep it; a role can get the rule back by hand.
 */
final class Version20261105090000 extends Migration
{
    private const string RULE = 'design.*';

    public function getDescription(): string
    {
        return 'Takes the design away from the role editor';
    }

    public function up(Schema $schema): void
    {
        $this->change(static fn(array $rules): array => array_values(array_filter($rules, static fn(mixed $rule): bool => self::RULE !== $rule)));
    }

    public function down(Schema $schema): void
    {
        $this->change(static fn(array $rules): array => \in_array(self::RULE, $rules, true) ? $rules : [...$rules, self::RULE]);
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
