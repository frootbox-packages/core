<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Database\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Frootbox\Core\Database\Schema\Blueprint;
use Frootbox\Core\Database\Schema\ColumnDefinition;
use Frootbox\Core\Database\Schema\SchemaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks the generated SQL for every supported database, without a connection.
 */
#[CoversClass(Blueprint::class)]
#[CoversClass(ColumnDefinition::class)]
final class BlueprintTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, list<string>}>
     */
    public static function platforms(): iterable
    {
        yield 'SQLite' => [new SQLitePlatform(), [
            'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL',
            'title VARCHAR(255) NOT NULL',
            'content CLOB DEFAULT NULL',
            'price NUMERIC(8, 2) NOT NULL',
            'visible BOOLEAN DEFAULT 1 NOT NULL',
            'created_at DATETIME DEFAULT NULL',
            'CREATE UNIQUE INDEX news_slug_unique ON news (slug)',
            'CREATE INDEX news_published_on_visible_index ON news (published_on, visible)',
        ]];

        yield 'MySQL' => [new MySQL80Platform(), [
            'id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL',
            'title VARCHAR(255) NOT NULL',
            'content JSON DEFAULT NULL',
            'price NUMERIC(8, 2) NOT NULL',
            'visible TINYINT DEFAULT 1 NOT NULL',
            'PRIMARY KEY (id)',
            'UNIQUE INDEX news_slug_unique (slug)',
            'INDEX news_published_on_visible_index (published_on, visible)',
        ]];

        yield 'MariaDB' => [new MariaDBPlatform(), [
            'id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL',
            'content JSON DEFAULT NULL',
            'PRIMARY KEY (id)',
            'UNIQUE INDEX news_slug_unique (slug)',
        ]];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('platforms')]
    public function testCreatesPortableTable(AbstractPlatform $platform, array $expected): void
    {
        $table = new Blueprint('news');
        $table->id();
        $table->string('title');
        $table->string('slug', 100)->unique();
        $table->json('content')->nullable();
        $table->decimal('price', 8, 2);
        $table->date('published_on');
        $table->boolean('visible')->default(true);
        $table->timestamps();
        $table->index(['published_on', 'visible']);

        $sql = implode("\n", $platform->getCreateTableSQL($table->toTable()));

        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }

    public function testCompositePrimaryKey(): void
    {
        $table = new Blueprint('news_tags');
        $table->bigInteger('news_id');
        $table->bigInteger('tag_id');
        $table->primary('news_id', 'tag_id');

        $sql = implode("\n", new MySQL80Platform()->getCreateTableSQL($table->toTable()));

        self::assertStringContainsString('PRIMARY KEY (news_id, tag_id)', $sql);
    }

    public function testShortensLongIndexNames(): void
    {
        $table = new Blueprint(str_repeat('t', 40));
        $table->string(str_repeat('c', 40))->index();

        $sql = implode("\n", new SQLitePlatform()->getCreateTableSQL($table->toTable()));

        self::assertMatchesRegularExpression('/CREATE INDEX (\w{64}) ON/', $sql);
    }

    public function testCannotDropColumnsOfANewTable(): void
    {
        $table = new Blueprint('news');
        $table->dropColumn('title');

        $this->expectException(SchemaException::class);

        $table->toTable();
    }

    public function testRejectsEmptyNames(): void
    {
        $this->expectException(SchemaException::class);

        new Blueprint('news')->string('');
    }
}
