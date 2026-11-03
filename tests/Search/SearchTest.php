<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Search;

use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\DeletePage;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Search\Search;
use Frootbox\Core\Search\SearchHit;
use Frootbox\Core\Search\SearchIndex;
use Frootbox\Core\Search\SearchIndexer;
use Frootbox\Core\Search\SearchRanking;
use Frootbox\Core\Search\SearchRebuildCommand;
use Frootbox\Core\Search\SearchSources;
use Frootbox\Core\Search\SearchTerms;
use Frootbox\Core\Search\Sources\PageSource;
use Frootbox\Core\Search\Sources\UserSource;
use Frootbox\Core\Search\UpdateSearchIndex;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Search::class)]
#[CoversClass(SearchIndex::class)]
#[CoversClass(SearchIndexer::class)]
#[CoversClass(SearchSources::class)]
#[CoversClass(SearchTerms::class)]
#[CoversClass(SearchRanking::class)]
#[CoversClass(SearchRebuildCommand::class)]
#[CoversClass(UpdateSearchIndex::class)]
#[CoversClass(PageSource::class)]
#[CoversClass(UserSource::class)]
#[CoversClass(FieldTypes::class)]
final class SearchTest extends NotesTestCase
{
    private string $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start']]))->id;
    }

    public function testFindsAPageByAWordOfItsBlocks(): void
    {
        $id = $this->page('Unser Kaffee', ['fields' => ['heading' => 'Willkommen'], 'areas' => ['main' => [['block' => 'acme/base/Teaser', 'fields' => ['title' => 'Frisch geröstet', 'text' => self::paragraph('Jeden Morgen aus der Rösterei am Hafen.')]]]]]);

        $hits = $this->search('rösterei hafen');

        self::assertCount(1, $hits);
        self::assertSame(['page', $id, 'Unser Kaffee', 'de'], [$hits[0]->source, $hits[0]->item, $hits[0]->title, $hits[0]->language]);
        self::assertSame('Jeden Morgen aus der Rösterei am Hafen.', $hits[0]->excerpt, 'The line with the word.');
        self::assertSame([], $this->search('rösterei bahnhof'), 'Every word must occur.');
        self::assertCount(1, $this->search('röst'), 'Parts of words count.');
    }

    public function testFollowsChangesAndDeletes(): void
    {
        $id = $this->page('Kontakt');
        self::assertSame([], $this->search('anfahrt'));

        $this->runner()->run(new UpdatePage($id, translations: ['de' => ['title' => 'Anfahrt']]));
        self::assertSame(['Anfahrt'], self::titles($this->search('anfahrt')), 'The draft counts.');

        $this->runner()->run(new DeletePage($id));
        self::assertSame([], $this->search('anfahrt'));
    }

    public function testFindsEverySpelling(): void
    {
        $this->page('Київ', language: 'uk');
        $this->page('Größe der Tassen');

        self::assertSame(['Київ'], self::titles($this->search('kyiv')));
        self::assertSame(['Größe der Tassen'], self::titles($this->search('groesse')), 'German rules.');
        self::assertSame(['Größe der Tassen'], self::titles($this->search('grosse')), 'General rules.');
        self::assertSame(['Größe der Tassen'], self::titles($this->search('GRÖSSE')));
    }

    public function testCountsAPageOnceWithAllItsLanguages(): void
    {
        $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Espresso'], 'uk' => ['title' => 'Espresso']], $this->start));

        $hits = $this->search('espresso');

        self::assertCount(1, $hits);
        self::assertEqualsCanonicalizing(['de', 'uk'], $hits[0]->languages);
    }

    public function testRanksTheTitleFirst(): void
    {
        $this->page('Über uns', ['fields' => ['heading' => 'Wir lieben Kaffee']]);
        $this->page('Kaffeesorten');
        $this->page('Kaffee');

        self::assertSame(['Kaffee', 'Kaffeesorten', 'Über uns'], self::titles($this->search('kaffee')));
    }

    public function testShowsOnlyPagesWithinTheBranchOfTheUser(): void
    {
        $about = $this->page('Über uns');
        $this->page('Team Kaffee', parentId: $about);
        $this->page('Kontakt Kaffee');

        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane'))->user;
        $this->runner()->run(new AssignRole($user->id, 'editor', 'page:' . $about));
        $jane = new Context(Actor::user($user->id, $user->name), Channel::Web);

        self::assertSame(['Team Kaffee'], self::titles($this->search('kaffee', $jane)));
        self::assertSame([], $this->search('jane', $jane), 'Users need "user.list".');
        self::assertSame(['Jane'], self::titles($this->search('jane')));
    }

    public function testFindsItemsOfAnExtension(): void
    {
        $this->runner()->run(new CreateNote('Einkaufsliste', 'Bohnen und Milch'));

        $hits = $this->search('milch');

        self::assertCount(1, $hits);
        self::assertSame(['acme/notes/note', 'Notes', 'Einkaufsliste'], [$hits[0]->source, $hits[0]->sourceTitle, $hits[0]->title]);
        self::assertSame([], $this->runner()->ask(new Search('milch', sources: ['page'])), 'Only the sources asked for.');
    }

    public function testFillsAnEmptyIndexOnTheFirstSearch(): void
    {
        $this->page('Teesorten');
        $this->get(SearchIndex::class)->clear();

        self::assertSame(['Teesorten'], self::titles($this->search('tee')));
    }

    public function testRebuildsTheIndexFromTheConsole(): void
    {
        $this->page('Teesorten');
        $this->connection()->executeStatement('UPDATE search_index SET terms = ?', [' nichts ']);
        self::assertSame([], $this->search('tee'));

        $tester = new CommandTester($this->get(SearchRebuildCommand::class));
        self::assertSame(0, $tester->execute(['source' => 'page']));
        self::assertStringContainsString('page', $tester->getDisplay());
        self::assertSame(['Teesorten'], self::titles($this->search('tee')));

        self::assertSame(1, $tester->execute(['source' => 'nope']), 'Unknown sources are named.');
    }

    public function testKeepsThePublishedStateApartForTheWebsite(): void
    {
        $id = $this->page('Röstkurs', ['fields' => ['heading' => 'Jeden Samstag']]);
        $this->page('Teekurs');
        $indexer = $this->get(SearchIndexer::class);

        self::assertSame(['page'], array_keys($indexer->rebuild(null, SearchIndex::LIVE)), 'Only sources that describe what is published.');
        self::assertCount(0, $this->live('röstkurs'), 'Drafts are not on the website.');

        $this->runner()->run(new UpdatePage($this->start, translations: ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Willkommen']]]]));
        $this->runner()->run(new PublishPages([$this->start, $id]));
        $indexer->rebuild('page', SearchIndex::LIVE);
        $rows = $this->live('kurs');
        self::assertSame([['Röstkurs', 'page:' . $id, '']], array_map(static fn(array $row): array => [$row['title'], $row['target'], $row['permission']], $rows));
        self::assertSame('Jeden Samstag', $rows[0]['body'] ?? null, 'The text without the slug.');

        $this->runner()->run(new UpdatePage($id, translations: ['de' => ['title' => 'Röstkurs am Samstag']]));
        $indexer->changed([], SearchIndex::LIVE);
        self::assertSame('Röstkurs', $this->live('kurs')[0]['title'] ?? null, 'A draft does not change it.');
        self::assertSame(['Röstkurs am Samstag'], self::titles($this->search('samstag kurs')), 'The global search shows the draft.');

        $tester = new CommandTester($this->get(SearchRebuildCommand::class));
        $this->get(SearchIndex::class)->clear(null, SearchIndex::LIVE);
        self::assertSame(0, $tester->execute(['--website' => true]));
        self::assertCount(1, $this->live('kurs'));
        self::assertCount(0, $this->live('kurs', 'uk'), 'Only the language asked for.');
    }

    public function testStoresBothSpellings(): void
    {
        self::assertSame(' groesse tassen grosse ', SearchTerms::column('Größe Tassen', 'de'));
        self::assertSame('kyiv', SearchTerms::normalize('Київ', 'uk'));
        self::assertSame(SearchTerms::normalize('القاهرة'), SearchTerms::normalize('القَاهِرَة'), 'Without vowel marks.');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch'], 'uk' => ['title' => 'Українська']]];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function page(string $title, array $content = [], ?string $parentId = null, string $language = 'de'): string
    {
        return $this->runner()->run(new CreatePage('acme/base/Page', [$language => ['title' => $title, 'content' => $content]], $parentId ?? $this->start))->id;
    }

    /**
     * @return list<SearchHit>
     */
    private function search(string $q, ?Context $context = null): array
    {
        return $this->runner()->ask(new Search($q), $context);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function live(string $q, string $language = 'de'): array
    {
        return $this->get(SearchIndex::class)->candidates(SearchRanking::words($q, $language), null, SearchIndex::LIVE, $language);
    }

    /**
     * @param list<SearchHit> $hits
     *
     * @return list<string>
     */
    private static function titles(array $hits): array
    {
        return array_map(static fn(SearchHit $hit): string => $hit->title, $hits);
    }

    /**
     * @return array<string, mixed>
     */
    private static function paragraph(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }
}
