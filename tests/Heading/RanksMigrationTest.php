<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Heading;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Core\Migrations\Version20261110090000;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(Version20261110090000::class)]
final class RanksMigrationTest extends NotesTestCase
{
    public function testTurnsLevelsIntoRanks(): void
    {
        $now = '2026-11-01 12:00:00';
        $content = ['fields' => ['body' => ['type' => 'doc', 'content' => [
            self::heading(2, 'Big'),
            self::heading(3, 'Below'),
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Text with "heading" in it']]],
            self::heading(4, 'Small'),
        ]], 'title' => 'Keeps {"type":"heading"} as text']];
        $this->connection()->insert('page_translations', ['page_id' => '01JBX0000000000000000000AA', 'language' => 'de', 'title' => 'T', 'slug' => 't', 'content' => json_encode($content, \JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        $this->connection()->insert('revisions', ['type' => 'page', 'entity_id' => '01JBX0000000000000000000AA', 'status' => 'draft', 'data' => json_encode(['translations' => ['de' => ['content' => $content]]], \JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        $this->connection()->insert('revisions', ['type' => 'page', 'entity_id' => '01JBX0000000000000000000AB', 'status' => 'draft', 'data' => '{"title":"Plain"}', 'created_at' => $now, 'updated_at' => $now]);

        $migration = new Version20261110090000($this->connection(), new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection()->executeStatement($query->getStatement(), array_values($query->getParameters()));
        }

        $translation = $this->json('SELECT content FROM page_translations');
        $revision = $this->json("SELECT data FROM revisions WHERE entity_id = '01JBX0000000000000000000AA'");
        $expected = [...$content, 'fields' => [...$content['fields'], 'body' => ['type' => 'doc', 'content' => [
            self::heading(1, 'Big'),
            self::heading(2, 'Below'),
            $content['fields']['body']['content'][2],
            self::heading(3, 'Small'),
        ]]]];

        self::assertSame($expected, $translation);
        self::assertSame(['translations' => ['de' => ['content' => $expected]]], $revision);
        self::assertSame('{"title":"Plain"}', $this->connection()->fetchOne("SELECT data FROM revisions WHERE entity_id = '01JBX0000000000000000000AB'"));
    }

    private function json(string $sql): mixed
    {
        $json = $this->connection()->fetchOne($sql);
        self::assertIsString($json);

        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function heading(int $level, string $text): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
