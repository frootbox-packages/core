<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Backup;

use Frootbox\Core\Backup\MysqlDriver;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Fixtures\Notes\Note;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Runs only against MySQL and MariaDB (DATABASE_URL in the CI).
 */
#[CoversClass(MysqlDriver::class)]
final class MysqlDumpTest extends NotesTestCase
{
    public function testTheDumpBringsTheRowsBack(): void
    {
        if (!str_starts_with(TestDatabase::url(), 'pdo-mysql')) {
            self::markTestSkipped('Needs MySQL or MariaDB.');
        }

        $this->runner()->run(new CreateNote("It's \"quoted\"; with a\nnew line", body: null, priority: 3));
        $file = $this->get(ProjectPaths::class)->root . '/storage/dump.sql.gz';
        new MysqlDriver($this->connection())->create($file);
        $sql = (string) gzdecode((string) file_get_contents($file));

        self::assertStringContainsString('CREATE TABLE `notes`', $sql);
        self::assertStringContainsString('INSERT INTO `notes` VALUES', $sql);

        $this->connection()->executeStatement('DELETE FROM notes');
        self::assertSame(0, $this->notes()->count());

        // The way mysql reads a dump, statement by statement.
        $pdo = $this->connection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);
        $pdo->exec($sql);

        $notes = iterator_to_array($this->notes()->fetch(), false);
        self::assertSame(["It's \"quoted\"; with a\nnew line"], array_map(static fn(Note $note): string => $note->title, $notes));
        self::assertSame(3, $notes[0]->priority);
    }
}
