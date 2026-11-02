<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Help;

use Frootbox\Core\Account\UpdateLanguage;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Help\HelpAccess;
use Frootbox\Core\Help\HelpEntry;
use Frootbox\Core\Help\HelpLibrary;
use Frootbox\Core\Help\InstallationHelp;
use Frootbox\Core\Help\ListHelp;
use Frootbox\Core\Help\ShowHelp;
use Frootbox\Core\Search\Search;
use Frootbox\Core\Search\SearchHit;
use Frootbox\Core\Search\SearchIndexer;
use Frootbox\Core\Search\Sources\HelpSource;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Frootbox\Database\EntityNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(HelpLibrary::class)]
#[CoversClass(HelpAccess::class)]
#[CoversClass(ListHelp::class)]
#[CoversClass(ShowHelp::class)]
#[CoversClass(InstallationHelp::class)]
#[CoversClass(HelpSource::class)]
#[CoversClass(SearchIndexer::class)]
final class HelpTest extends NotesTestCase
{
    private Context $editor;

    private Context $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->user('editor@example.org', 'editor');
        $this->admin = $this->user('admin@example.org', 'admin');
    }

    public function testListsWhatTheUserMayRead(): void
    {
        $editor = self::ids($this->runner()->ask(new ListHelp(), $this->editor));
        $admin = self::ids($this->runner()->ask(new ListHelp(), $this->admin));

        self::assertContains('acme/helpful/teaser', $editor);
        self::assertNotContains('acme/helpful/secret', $editor, 'Help about users needs "user.list".');
        self::assertContains('acme/helpful/secret', $admin);
        self::assertContains(InstallationHelp::BLOCKS, $editor);
        self::assertNotContains(InstallationHelp::ROLES, $editor);
        self::assertContains(InstallationHelp::ROLES, $admin);
        self::assertLessThan(array_search('acme/helpful/secret', $admin, true), array_search('acme/helpful/teaser', $admin, true), 'In their order.');
    }

    public function testFindsThePagesAboutAScreen(): void
    {
        $entries = $this->runner()->ask(new ListHelp('screen:backend.pages'), $this->editor);

        self::assertSame(['acme/helpful/teaser'], self::ids($entries));
        self::assertSame('Der Teaser', $entries[0]->title, 'In the language of the backend.');
    }

    public function testShowsAPageAsSafeHtml(): void
    {
        $reader = $this->user('reader@example.org', 'admin');
        $this->runner()->run(new UpdateLanguage('en'), $reader);
        $article = $this->runner()->ask(new ShowHelp('acme/helpful/teaser', '/cms/help/{id}'), $reader);

        self::assertSame('The teaser', $article->title);
        self::assertStringContainsString('<a href="/cms/help/acme/helpful/secret#how-it-works">the secret</a>', $article->html);
        self::assertStringContainsString('<a href="https://example.org/docs" rel="noopener noreferrer">elsewhere</a>', $article->html);
        self::assertStringContainsString('never this', $article->html, 'Other schemes lose their link.');
        self::assertStringContainsString('<h2 id="arrange-it">Arrange it</h2>', $article->html, 'Below the title of the page.');
        self::assertStringContainsString('<ol><li>Add the block.</li><li>Fill the <strong>title</strong>.</li></ol>', $article->html);
        self::assertMatchesRegularExpression('~<img src="/assets/acme/helpful/help/assets/tree.png" alt="The tree" loading="lazy">~', $article->html);
        self::assertStringNotContainsString('pixel.png', $article->html, 'No images of others.');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $article->html);
        self::assertStringNotContainsString('<script', $article->html);
    }

    public function testFallsBackToEnglish(): void
    {
        $article = $this->runner()->ask(new ShowHelp('acme/helpful/secret'), $this->admin);

        self::assertSame(['en', false], [$article->language, $article->translated]);

        $german = $this->runner()->ask(new ShowHelp('acme/helpful/teaser'), $this->admin);
        self::assertSame(['de', true, 'Der Teaser'], [$german->language, $german->translated, $german->title]);
        self::assertStringContainsString('href="/api/v1/help.show?id=acme/helpful/secret"', $german->html, 'Links of the REST API by default.');
    }

    public function testHidesWhatTheUserMayNotRead(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->runner()->ask(new ShowHelp('acme/helpful/secret'), $this->editor);
    }

    public function testDescribesTheBlocksOfTheTheme(): void
    {
        $article = $this->runner()->ask(new ShowHelp(InstallationHelp::BLOCKS), $this->editor);

        self::assertStringContainsString('<h2 id="teaser">Teaser</h2>', $article->html);
        self::assertStringContainsString('<p>Heading, text and a button.</p>', $article->html);
    }

    public function testTheSearchFindsHelpAndReadsChangedFilesAgain(): void
    {
        $hits = $this->runner()->ask(new Search('teaser', ['help']), $this->editor);
        self::assertSame(['acme/helpful/teaser'], array_map(static fn(SearchHit $hit): string => $hit->item, array_filter($hits, static fn(SearchHit $hit): bool => str_starts_with($hit->item, 'acme/'))));
        self::assertSame([], $this->runner()->ask(new Search('geheimnis users', ['help']), $this->editor), 'Hidden help is not found.');

        $file = FixtureSite::path('helpful') . '/help/en/secret.md';
        $original = (string) file_get_contents($file);

        try {
            file_put_contents($file, str_replace('Only for those', 'Only for kangaroos', $original));
            touch($file, time() + 10);
            self::assertSame([], $this->runner()->ask(new Search('kangaroos', ['help']), $this->editor));
            self::assertCount(1, $this->runner()->ask(new Search('kangaroos', ['help']), $this->admin), 'Read again after the change.');
        } finally {
            file_put_contents($file, $original);
        }
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'helpful');
    }

    protected function config(): array
    {
        return ['theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function user(string $email, string $role): Context
    {
        $user = $this->runner()->run(new CreateUser($email, ucfirst($role), roles: [$role]), Context::system())->user;

        return new Context(Actor::user($user->id, $user->name), Channel::Web);
    }

    /**
     * @param list<HelpEntry> $entries
     *
     * @return list<string>
     */
    private static function ids(array $entries): array
    {
        return array_map(static fn(HelpEntry $entry): string => $entry->id, $entries);
    }
}
