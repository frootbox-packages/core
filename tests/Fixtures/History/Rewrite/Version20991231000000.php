<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\History\Rewrite;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Database\Migration;

/**
 * Test migration: rewrites the titles of notes like a migration that
 * changes content.
 */
final class Version20991231000000 extends Migration
{
    public function getDescription(): string
    {
        return 'Writes the titles of notes in capitals';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, title FROM notes') as $row) {
            $title = \is_string($row['title']) ? $row['title'] : '';
            $id = \is_int($row['id']) || \is_string($row['id']) ? $row['id'] : 0;

            if (mb_strtoupper($title) !== $title) {
                $this->updateRow('notes', $id, ['title' => $title], ['title' => mb_strtoupper($title)]);
            }
        }
    }

    public function down(Schema $schema): void {}
}
