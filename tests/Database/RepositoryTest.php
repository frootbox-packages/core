<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Database;

use DateTimeImmutable;
use Frootbox\Core\Database\Entity;
use Frootbox\Core\Database\EntityMapper;
use Frootbox\Core\Database\EntityNotFoundException;
use Frootbox\Core\Database\Paginated;
use Frootbox\Core\Database\Query\Criteria;
use Frootbox\Core\Database\Repository;
use Frootbox\Core\Database\RepositoryException;
use Frootbox\Core\Database\Result;
use Frootbox\Core\Database\Values;
use Frootbox\Core\Database\Where;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Fixtures\Notes\Note;
use Frootbox\Core\Tests\Fixtures\Notes\NoteStatus;
use Frootbox\Core\Tests\Fixtures\Notes\RenameNote;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Repository::class)]
#[CoversClass(Criteria::class)]
#[CoversClass(EntityMapper::class)]
#[CoversClass(Entity::class)]
#[CoversClass(Result::class)]
#[CoversClass(Paginated::class)]
#[CoversClass(Values::class)]
#[CoversClass(Where::class)]
final class RepositoryTest extends NotesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->create('Alpha', priority: 1, status: NoteStatus::Published, tags: ['red']);
        $this->create('Beta', priority: 5, publishedAt: new DateTimeImmutable('2026-01-01 10:00:00'));
        $this->create('Gamma', priority: 5, status: NoteStatus::Published);
        $this->create('Delta', priority: 9, body: 'Text');
    }

    public function testFetchesTypedEntities(): void
    {
        $alpha = $this->notes()->fetchOne(['where' => ['title' => 'Alpha']]);

        self::assertInstanceOf(Note::class, $alpha);
        self::assertSame(1, $alpha->priority);
        self::assertSame(NoteStatus::Published, $alpha->status);
        self::assertSame(['red'], $alpha->tags);
        self::assertFalse($alpha->pinned);
        self::assertNull($alpha->body);
        self::assertNull($alpha->publishedAt);
        self::assertLessThanOrEqual(new DateTimeImmutable(), $alpha->createdAt);
    }

    public function testWhereConditions(): void
    {
        self::assertSame(['Beta', 'Gamma'], $this->titles(['where' => ['priority' => 5]]));
        self::assertSame(['Alpha', 'Gamma'], $this->titles(['where' => ['status' => NoteStatus::Published]]));
        self::assertSame(['Beta', 'Gamma', 'Delta'], $this->titles(['where' => ['priority >=' => 5]]));
        self::assertSame(['Alpha', 'Delta'], $this->titles(['where' => ['priority !=' => 5]]));
        self::assertSame(['Alpha', 'Delta'], $this->titles(['where' => ['title' => ['Alpha', 'Delta', 'Omega']]]));
        self::assertSame(['Beta', 'Gamma'], $this->titles(['where' => ['title not in' => ['Alpha', 'Delta']]]));
        self::assertSame(['Delta'], $this->titles(['where' => ['body !=' => null]]));
        self::assertSame(['Beta'], $this->titles(['where' => ['publishedAt <' => new DateTimeImmutable('2026-06-01')]]));
        self::assertSame(['Gamma'], $this->titles(['where' => ['title like' => '%amm%']]));
        self::assertSame([], $this->titles(['where' => ['title' => []]]));
        self::assertSame(['Alpha', 'Delta'], $this->titles(['where' => [Where::any(['priority' => 1], ['body' => 'Text'])]]));
        self::assertSame(['Gamma'], $this->titles(['where' => ['status' => 'published', Where::any(['priority >' => 3], ['title' => 'Zeta'])]]));
    }

    public function testOrderLimitAndOffset(): void
    {
        self::assertSame(['Delta', 'Beta', 'Gamma', 'Alpha'], $this->titles(['order' => ['priority' => 'desc', 'title']]));
        self::assertSame(['Delta', 'Beta'], $this->titles(['order' => 'title desc', 'limit' => 2, 'offset' => 1]));
    }

    public function testPaginates(): void
    {
        $page = $this->notes()->paginate(['order' => 'title'], page: 2, perPage: 3);

        self::assertSame(4, $page->total);
        self::assertSame(2, $page->pages());
        self::assertCount(1, $page);
        self::assertTrue($page->hasPreviousPage());
        self::assertFalse($page->hasNextPage());
        self::assertSame('Gamma', $page->items->first()?->title);
        self::assertSame(2, $page->jsonSerialize()['pages']);
    }

    public function testCountsAndFindsById(): void
    {
        self::assertSame(4, $this->notes()->count());
        self::assertSame(2, $this->notes()->count(['priority' => 5]));

        $id = $this->notes()->fetchOne(['where' => ['title' => 'Delta']])->id ?? 0;
        self::assertSame('Delta', $this->notes()->getById($id)->title);
        self::assertNull($this->notes()->fetchById(999999));

        $this->expectException(EntityNotFoundException::class);
        $this->notes()->getById(999999);
    }

    public function testFillsTimestamps(): void
    {
        $note = $this->notes()->fetchOne(['where' => ['title' => 'Alpha']]);
        self::assertNotNull($note);
        self::assertNotNull($note->updatedAt);

        $renamed = $this->runner()->run(new RenameNote($note->id, 'Alpha 2'));

        self::assertSame('Alpha 2', $renamed->title);
        self::assertEquals($note->createdAt, $renamed->createdAt);
        self::assertGreaterThanOrEqual($note->updatedAt, $renamed->updatedAt);
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->expectException(RepositoryException::class);
        $this->expectExceptionMessage('Unknown option "orderBy"');

        $this->notes()->fetch(['orderBy' => 'title']);
    }

    public function testRejectsInjectedColumnNames(): void
    {
        $this->expectException(RepositoryException::class);

        $this->notes()->fetch(['where' => ['title; DROP TABLE notes' => 'x']]);
    }

    public function testResultHelpers(): void
    {
        $result = $this->notes()->fetch(['order' => 'title']);

        self::assertFalse($result->isEmpty());
        self::assertSame('Alpha', $result->first()?->title);
        self::assertCount(4, $result->all());
        self::assertSame('Alpha', $result->jsonSerialize()[0]['title'] ?? null);
        self::assertTrue($this->notes()->fetch(['where' => ['title' => 'Zeta']])->isEmpty());
    }

    public function testColumnNames(): void
    {
        self::assertSame('published_at', Values::column('publishedAt'));
        self::assertSame('published_at', Values::column('published_at'));
        self::assertSame('id', Values::column('id'));
    }

    /**
     * @param array{where?: array<int|string, mixed>, order?: string|array<int|string, string>, limit?: int, offset?: int} $options
     *
     * @return list<string>
     */
    private function titles(array $options): array
    {
        return $this->notes()->fetch($options + ['order' => 'id'])->map(static fn(Note $note): string => $note->title);
    }

    /**
     * @param list<string> $tags
     */
    private function create(string $title, int $priority = 0, NoteStatus $status = NoteStatus::Draft, array $tags = [], ?string $body = null, ?DateTimeImmutable $publishedAt = null): void
    {
        $this->runner()->run(new CreateNote($title, $body, $priority, $status, $tags, $publishedAt));
    }
}
