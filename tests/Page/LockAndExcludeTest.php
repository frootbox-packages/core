<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Navigation\MenuItem;
use Frootbox\Core\Navigation\Menus;
use Frootbox\Core\Navigation\PublishNavigations;
use Frootbox\Core\Navigation\UpdateNavigation;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\LockPage;
use Frootbox\Core\Page\MovePage;
use Frootbox\Core\Page\PageData;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PageOutline;
use Frootbox\Core\Page\PageVisibility;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\Reach;
use Frootbox\Core\Page\ShowPage;
use Frootbox\Core\Page\ShowPageTree;
use Frootbox\Core\Page\UnlockPage;
use Frootbox\Core\Page\UnpublishPage;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\Delivery;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(LockPage::class)]
#[CoversClass(UnlockPage::class)]
#[CoversClass(Reach::class)]
#[CoversClass(PageVisibility::class)]
#[CoversClass(PageOutline::class)]
#[CoversClass(PageData::class)]
#[CoversClass(PageHandler::class)]
#[CoversClass(Menus::class)]
final class LockAndExcludeTest extends NotesTestCase
{
    private string $start;

    private string $about;

    private string $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->createPage(null, 'Start');
        $this->about = $this->createPage($this->start, 'Über uns');
        $this->team = $this->createPage($this->about, 'Team');
        $this->runner()->run(new PublishPages([$this->start, $this->about, $this->team]));
    }

    public function testFindsWhereASettingComesFrom(): void
    {
        self::assertNull(Reach::source([]));
        self::assertNull(Reach::source(['a' => Reach::Page, 'b' => Reach::None]), 'Page reaches the page only.');
        self::assertSame('a', Reach::source(['a' => Reach::Branch, 'b' => Reach::None]));
        self::assertSame('b', Reach::source(['a' => Reach::Branch, 'b' => Reach::Page]), 'The own setting comes first.');
        self::assertSame('b', Reach::source(['a' => Reach::Branch, 'b' => Reach::Branch, 'c' => Reach::None]), 'The nearest page above.');
    }

    public function testLocksAPageForVisitorsRightAway(): void
    {
        self::assertSame('miss', $this->request('/ueber-uns')->getHeaderLine(PageCache::HEADER));

        $details = $this->runner()->run(new LockPage($this->about));

        self::assertSame(Reach::Page, $details->locked);
        self::assertSame($this->about, $details->visibility->lockedBy);
        self::assertNull($details->draft, 'Locking makes no draft.');
        $this->assertLocked('/ueber-uns');
        $this->assertLocked('/ueber-uns', 'The cache is cleared.');
        self::assertSame(200, $this->request('/ueber-uns/team')->getStatusCode(), 'Subpages stay without branch.');

        $tree = $this->runner()->ask(new ShowPageTree());
        self::assertSame(Reach::Page, $tree[0]->children[0]->locked);
        self::assertNull($tree[0]->children[0]->children[0]->visibility->lockedBy);

        // Publishing keeps the lock, unlocking lifts it.
        $this->runner()->run(new UpdatePage($this->about, ['de' => ['title' => 'Wir']]));
        $this->runner()->run(new PublishPages([$this->about]));
        $this->assertLocked('/ueber-uns');
        $unlocked = $this->runner()->run(new UnlockPage($this->about));
        self::assertSame(Reach::None, $unlocked->locked);
        self::assertNull($unlocked->visibility->lockedBy);
        self::assertSame(200, $this->request('/ueber-uns')->getStatusCode());
    }

    public function testLocksABranch(): void
    {
        $this->runner()->run(new LockPage($this->about, branch: true));

        $this->assertLocked('/ueber-uns/team');
        $team = $this->runner()->ask(new ShowPage($this->team));
        self::assertSame(Reach::None, $team->locked);
        self::assertSame($this->about, $team->visibility->lockedBy, 'The lock comes from above.');

        $this->runner()->run(new UnlockPage($this->team));
        $this->assertLocked('/ueber-uns/team', 'A lock above stays.');

        // The lock follows the published tree, not a move in the draft.
        $this->runner()->run(new MovePage($this->team, $this->start));
        self::assertSame($this->about, $this->runner()->ask(new ShowPage($this->team))->visibility->lockedBy, 'Visitors still find it below the locked page.');
        $this->assertLocked('/ueber-uns/team');

        // Taking the page offline lifts the lock.
        $this->runner()->run(new UnpublishPage($this->about));
        $this->runner()->run(new PublishPages([$this->about, $this->team]));
        self::assertSame(200, $this->request('/ueber-uns')->getStatusCode());
        self::assertSame(200, $this->request('/team')->getStatusCode());
    }

    public function testOnlyLocksPublishedPages(): void
    {
        $draft = $this->createPage($this->start, 'Neu');

        try {
            $this->runner()->run(new LockPage($draft));
            self::fail('A draft was locked.');
        } catch (InvalidInputException $error) {
            self::assertSame(['/id' => 'Only published pages can be locked.'], $error->errors);
        }
    }

    public function testLeavesLockedPagesOutOfNavigations(): void
    {
        $this->runner()->run(new UpdateNavigation('main', ['de' => [
            ['type' => 'pages', 'target' => 'page:' . $this->start, 'depth' => 2],
            ['type' => 'target', 'target' => 'page:' . $this->team],
        ]]));
        $this->runner()->run(new PublishNavigations(['main']));
        $this->runner()->run(new LockPage($this->about, branch: true));

        $this->get(Urls::class)->clear();
        $this->get(PageOutline::class)->clear();
        $this->get(Delivery::class)->set($this->get(Languages::class)->get('de'), target: Target::page($this->start));

        self::assertSame([], array_map(static fn(MenuItem $item): string => $item->label, $this->get(Menus::class)->navigation('main')));
    }

    public function testExcludesPagesFromSearchesAfterPublishing(): void
    {
        $details = $this->runner()->run(new UpdatePage($this->about, excludeFromSearchEngines: Reach::Branch, excludeFromSiteSearch: Reach::Page));

        self::assertSame(Reach::Branch, $details->draft?->excludeFromSearchEngines);
        self::assertSame($this->about, $details->visibility->excludedFromSearchEnginesBy, 'The tree and details show the draft.');
        self::assertStringNotContainsString('noindex', (string) $this->request('/ueber-uns/team')->getBody(), 'Visitors see it after publishing.');

        $this->runner()->run(new PublishPages([$this->about]));

        self::assertStringContainsString('<meta name="robots" content="noindex">', (string) $this->request('/ueber-uns')->getBody());
        self::assertStringContainsString('<meta name="robots" content="noindex">', (string) $this->request('/ueber-uns/team')->getBody(), 'The branch reaches the subpages.');
        self::assertStringNotContainsString('noindex', (string) $this->request('/')->getBody());

        $outline = $this->get(PageOutline::class);
        $outline->clear();
        self::assertSame($this->about, $outline->excludedFromSiteSearchBy($this->about));
        self::assertNull($outline->excludedFromSiteSearchBy($this->team), 'Page reaches the page only.');

        $team = $this->runner()->ask(new ShowPage($this->team));
        self::assertSame(Reach::None, $team->published?->excludeFromSearchEngines);
        self::assertSame($this->about, $team->visibility->excludedFromSearchEnginesBy);
        self::assertNull($team->visibility->excludedFromSiteSearchBy);

        // Left out, a value stays; older snapshots without it mean none.
        $kept = $this->runner()->run(new UpdatePage($this->about, ['de' => ['title' => 'Wir']]));
        self::assertSame(Reach::Branch, $kept->draft?->excludeFromSearchEngines);
        self::assertSame(Reach::None, PageData::fromArray(['parentId' => null, 'position' => 'a', 'layout' => 'x', 'translations' => []])->excludeFromSiteSearch);
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function createPage(?string $parentId, string $title): string
    {
        return $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => $title, 'content' => ['fields' => ['heading' => $title]]]], $parentId))->id;
    }

    private function request(string $path): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org' . $path));
    }

    private function assertLocked(string $path, string $message = ''): void
    {
        try {
            $this->request($path);
            self::fail(\sprintf('%s was answered.', $path));
        } catch (HttpException $error) {
            self::assertSame(503, $error->status, $message);
            self::assertSame(['X-Robots-Tag' => 'noindex'], $error->headers);
        }
    }
}
