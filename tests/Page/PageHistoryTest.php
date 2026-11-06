<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use DateTimeImmutable;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\History;
use Frootbox\Core\Action\HistoryEntry;
use Frootbox\Core\Action\HistoryPage;
use Frootbox\Core\Action\RowDiff;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\DiscardPage;
use Frootbox\Core\Page\PageChange;
use Frootbox\Core\Page\PageHistory;
use Frootbox\Core\Page\PageHistoryEntry;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\RemoveBlock;
use Frootbox\Core\Page\ShowPageHistory;
use Frootbox\Core\Page\UpdatePageField;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Who changed what on a page (decision 0174).
 */
#[CoversClass(ShowPageHistory::class)]
#[CoversClass(History::class)]
#[CoversClass(HistoryEntry::class)]
#[CoversClass(HistoryPage::class)]
#[CoversClass(PageHistory::class)]
#[CoversClass(PageHistoryEntry::class)]
#[CoversClass(PageChange::class)]
final class PageHistoryTest extends NotesTestCase
{
    private string $page;

    private string $teaser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Willkommen'], 'areas' => ['main' => [
            ['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Frisch']],
            ['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Geröstet']],
        ]]]]]))->id;
        $this->runner()->run(new PublishPages([$this->page]));

        $published = $this->get(PageReader::class)->published($this->page);
        self::assertNotNull($published);
        $areas = $published->translations['de']->content['areas'] ?? null;
        self::assertIsArray($areas);
        $main = $areas['main'] ?? null;
        self::assertIsArray($main);
        $first = $main[0] ?? null;
        self::assertIsArray($first);
        self::assertIsString($first['id']);
        $this->teaser = $first['id'];
    }

    public function testTellsWhoChangedWhichFieldOfWhichBlock(): void
    {
        $this->runner()->run(new UpdatePageField($this->page, 'de', 'title', 'Neu', 'Frisch', $this->teaser));
        $this->runner()->run(new UpdatePageField($this->page, 'de', 'heading', 'Hallo', 'Willkommen'));

        $history = $this->history();
        [$heading, $title] = $history->entries;

        self::assertSame('page.update-field', $title->action);
        self::assertSame('System', $title->actorName);
        self::assertTrue($title->undoable);
        self::assertSame('Neu', $title->input['value'] ?? null);
        self::assertEquals([new PageChange('update', 'draft', 'de', $this->teaser, 'acme/base/Teaser', 'fields/title', 'Frisch', 'Neu')], $title->changes, 'The first change of a draft compares with what was published.');
        self::assertEquals([new PageChange('update', 'draft', 'de', null, null, 'fields/heading', 'Willkommen', 'Hallo')], $heading->changes);

        self::assertSame(['page.update-field', 'page.update-field', 'page.publish', 'page.create'], array_map(static fn(PageHistoryEntry $entry): string => $entry->action, $history->entries));
        self::assertCount(1, $this->history(block: $this->teaser)->entries, 'Only the block.');
        self::assertCount(1, $this->history(path: 'fields/heading')->entries);
        self::assertSame([], $this->history(language: 'en', path: 'fields/heading')->entries);
    }

    public function testShowsWhatWasPublishedRemovedAndDiscarded(): void
    {
        $this->runner()->run(new RemoveBlock($this->page, 'de', $this->teaser));
        $this->runner()->run(new PublishPages([$this->page]));
        $this->runner()->run(new UpdatePageField($this->page, 'de', 'heading', 'Weg damit', 'Willkommen'));
        $this->runner()->run(new DiscardPage($this->page));

        [$discard, , $publish, $remove] = $this->history()->entries;
        self::assertSame(['page.discard', 'page.update-field', 'page.publish', 'page.remove-block', 'page.publish', 'page.create'], array_map(static fn(PageHistoryEntry $entry): string => $entry->action, $this->history()->entries));

        self::assertSame('page.discard', $discard->action);
        self::assertEquals([new PageChange('delete', 'draft', null, null, null, '')], $discard->changes, 'The draft is gone, its changes are in the entries before.');

        $removed = array_values(array_filter($remove->changes, static fn(PageChange $change): bool => null === $change->new));
        self::assertCount(1, $removed);
        self::assertSame([$this->teaser, 'acme/base/Teaser', ''], [$removed[0]->block, $removed[0]->blockType, $removed[0]->path]);
        self::assertSame('Frisch', RowDiff::at($removed[0]->old, 'fields/title'), 'The removed block with its content.');

        $live = array_values(array_filter($publish->changes, fn(PageChange $change): bool => 'published' === $change->state && $this->teaser === $change->block));
        self::assertNotEmpty($live, 'Publishing takes the block off the website.');
    }

    public function testTheHistoryStaysAfterTheChangesAreShortened(): void
    {
        $this->runner()->run(new UpdatePageField($this->page, 'de', 'heading', 'Hallo', 'Willkommen'));
        $this->runner()->run(new UpdatePageField($this->page, 'de', 'title', 'Neu', 'Frisch', $this->teaser));
        $before = $this->history();

        $this->get(ActionLog::class)->prune(new DateTimeImmutable('+1 minute'), new DateTimeImmutable('-1 year'));
        $after = $this->history();

        self::assertEquals($before->entries[0]->changes, $after->entries[0]->changes, 'An update of the draft reads the same.');
        self::assertFalse($after->entries[0]->undoable);
        self::assertSame('fields/title', $after->entries[0]->changes[0]->path);
        self::assertSame($this->teaser, $after->entries[0]->changes[0]->block);
    }

    public function testLeavesOutOtherPagesOfTheSameAction(): void
    {
        $other = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Andere Seite', 'content' => ['fields' => ['heading' => 'Geheim']]]], $this->page))->id;
        $this->runner()->run(new UpdatePageField($this->page, 'de', 'heading', 'Hallo', 'Willkommen'));
        $this->runner()->run(new PublishPages([$this->page, $other]));

        $publish = $this->history()->entries[0];
        self::assertSame('page.publish', $publish->action);
        self::assertNotEmpty($publish->changes);

        // The other page is new on the website, this one was there before.
        self::assertSame([], array_values(array_filter($publish->changes, static fn(PageChange $change): bool => 'insert' === $change->kind)));
    }

    public function testPagesThroughOlderEntries(): void
    {
        foreach (['Eins', 'Zwei', 'Drei'] as $index => $value) {
            $this->runner()->run(new UpdatePageField($this->page, 'de', 'heading', $value, ['Willkommen', 'Eins', 'Zwei'][$index]));
        }

        $first = $this->history(limit: 2);
        self::assertCount(2, $first->entries);
        self::assertNotNull($first->next);

        $second = $this->runner()->ask(new ShowPageHistory($this->page, limit: 2, before: $first->next));
        self::assertSame('Eins', $second->entries[0]->changes[0]->new);
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function history(?string $block = null, ?string $path = null, ?string $language = null, int $limit = 50): PageHistory
    {
        return $this->runner()->ask(new ShowPageHistory($this->page, $block, $path, $language, $limit));
    }
}
