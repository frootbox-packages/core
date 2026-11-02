<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use DateTimeImmutable;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Cache\ClearPageCache;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\ListPageVersions;
use Frootbox\Core\Page\PageEditor;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PageStatus;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\SchedulePage;
use Frootbox\Core\Page\ShowPage;
use Frootbox\Core\Page\ShowPageTree;
use Frootbox\Core\Page\UnpublishPage;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Schedule\Scheduler;
use Frootbox\Core\Schedule\ScheduleResult;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\CreateRedirect;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(PageCache::class)]
#[CoversClass(ClearPageCache::class)]
#[CoversClass(ActionLog::class)]
#[CoversClass(PageHandler::class)]
#[CoversClass(PageEditor::class)]
#[CoversClass(UnpublishPage::class)]
#[CoversClass(SchedulePage::class)]
#[CoversClass(RevisionStore::class)]
#[CoversClass(Revisions::class)]
#[CoversClass(Scheduler::class)]
#[CoversClass(ScheduleResult::class)]
final class CacheAndScheduleTest extends NotesTestCase
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

    public function testCachesPagesForGuests(): void
    {
        self::assertSame('miss', $this->request('/ueber-uns')->getHeaderLine(PageCache::HEADER));
        $hit = $this->request('/ueber-uns');
        self::assertSame('hit', $hit->getHeaderLine(PageCache::HEADER));
        self::assertStringContainsString('<h1>Über uns</h1>', (string) $hit->getBody());
        self::assertSame('hit', $this->request('/ueber-uns?utm_source=newsletter&x=1')->getHeaderLine(PageCache::HEADER), 'Parameters no block declares do not count.');
        self::assertSame('', $this->request('/ueber-uns', ['Authorization' => 'Bearer x'])->getHeaderLine(PageCache::HEADER));
        self::assertSame('miss', $this->request('/ueber-uns', host: 'example.com')->getHeaderLine(PageCache::HEADER), 'Each host on its own.');

        // A draft changes nothing for visitors.
        $this->runner()->run(new UpdatePage($this->about, ['de' => ['content' => ['fields' => ['heading' => 'Wir']]]]));
        self::assertSame('hit', $this->request('/ueber-uns')->getHeaderLine(PageCache::HEADER));

        $this->runner()->run(new PublishPages([$this->about]));
        $fresh = $this->request('/ueber-uns');
        self::assertSame('miss', $fresh->getHeaderLine(PageCache::HEADER));
        self::assertStringContainsString('<h1>Wir</h1>', (string) $fresh->getBody());

        // Anything else that visitors see clears it, too.
        self::assertSame('hit', $this->request('/ueber-uns')->getHeaderLine(PageCache::HEADER));
        $this->runner()->run(new CreateRedirect('/alt', 'page:' . $this->about));
        self::assertSame('miss', $this->request('/ueber-uns')->getHeaderLine(PageCache::HEADER));

        $development = new PageCache($this->get(ProjectPaths::class), Environment::Development);
        self::assertFalse($development->accepts(new ServerRequest('GET', 'https://example.org/')), 'No cache while developing.');
    }

    public function testTakesABranchOffline(): void
    {
        $details = $this->runner()->run(new UnpublishPage($this->about));

        self::assertSame(PageStatus::Draft, $details->status);
        self::assertNull($details->version);
        self::assertSame('Über uns', $details->draft?->translations['de']->title);
        self::assertSame(PageStatus::Draft, $this->runner()->ask(new ShowPage($this->team))->status, 'Subpages go with it.');
        self::assertFalse($this->runner()->ask(new ListPageVersions($this->about))[0]->current);
        $this->assertGone('/ueber-uns');
        $this->assertGone('/ueber-uns/team');
        self::assertSame(200, $this->request('/')->getStatusCode());

        try {
            $this->runner()->run(new UnpublishPage($this->about));
            self::fail('A draft was taken offline.');
        } catch (InvalidInputException $error) {
            self::assertSame(['/id' => \sprintf('The page %s is not published.', $this->about)], $error->errors);
        }

        $this->runner()->run(new PublishPages([$this->about, $this->team]));
        self::assertSame(2, $this->runner()->ask(new ShowPage($this->team))->version);
        self::assertSame(200, $this->request('/ueber-uns/team')->getStatusCode());
    }

    public function testPublishesAndTakesOfflineOnTime(): void
    {
        $now = new DateTimeImmutable('2026-11-01 12:00:00');
        $this->runner()->run(new UpdatePage($this->about, ['de' => ['title' => 'Wir']]));
        $details = $this->runner()->run(new SchedulePage($this->about, new DateTimeImmutable('2026-11-01T18:00:00+05:00'), new DateTimeImmutable('2026-11-03 12:00:00')));

        self::assertSame(new DateTimeImmutable('2026-11-01 13:00:00 UTC')->getTimestamp(), $details->publishAt?->getTimestamp(), 'Stored in the time zone of PHP.');
        self::assertSame(new DateTimeImmutable('2026-11-03 12:00:00')->getTimestamp(), $details->unpublishAt?->getTimestamp());

        $tree = $this->runner()->ask(new ShowPageTree());
        self::assertSame($details->publishAt->getTimestamp(), $tree[0]->children[0]->publishAt?->getTimestamp(), 'The tree shows that the page is scheduled.');

        $scheduler = $this->get(Scheduler::class);
        self::assertEquals(new ScheduleResult(), $scheduler->run($now));

        self::assertEquals(new ScheduleResult([$this->about]), $scheduler->run($now->modify('+1 day')));
        $published = $this->runner()->ask(new ShowPage($this->about));
        self::assertSame(PageStatus::Published, $published->status);
        self::assertSame('Wir', $published->published?->translations['de']->title);
        self::assertNull($published->publishAt);
        self::assertSame($details->unpublishAt->getTimestamp(), $published->unpublishAt?->getTimestamp(), 'Going offline stays planned for the new version.');

        self::assertEquals(new ScheduleResult([], [$this->about]), $scheduler->run($now->modify('+3 days')));
        self::assertSame(PageStatus::Draft, $this->runner()->ask(new ShowPage($this->team))->status);
        self::assertEquals(new ScheduleResult(), $scheduler->run($now->modify('+4 days')));
    }

    public function testChecksTheTimes(): void
    {
        $at = new DateTimeImmutable('2026-12-01 08:00:00');
        $cases = [
            [new SchedulePage($this->about, $at), ['/publishAt' => 'The page has no draft to publish.']],
            [new SchedulePage($this->createPage($this->start, 'Neu'), null, $at), ['/unpublishAt' => 'The page is not published.']],
        ];
        $this->runner()->run(new UpdatePage($this->team, ['de' => ['title' => 'Leute']]));
        $cases[] = [new SchedulePage($this->team, $at, $at), ['/unpublishAt' => 'must be after "publishAt".']];

        foreach ($cases as [$action, $errors]) {
            try {
                $this->runner()->run($action);
                self::fail('The times were accepted.');
            } catch (InvalidInputException $error) {
                self::assertSame($errors, $error->errors);
            }
        }
    }

    public function testDropsTimesThatCannotWork(): void
    {
        $parent = $this->createPage($this->start, 'Neu');
        $child = $this->createPage($parent, 'Kind');
        $this->runner()->run(new SchedulePage($child, new DateTimeImmutable('2026-01-01 00:00:00')));

        $result = $this->get(Scheduler::class)->run(new DateTimeImmutable('2026-02-01 00:00:00'));

        self::assertSame([], $result->published);
        self::assertCount(1, $result->failed);
        self::assertStringContainsString(\sprintf('The parent page %s is not published.', $parent), $result->failed[0]);
        self::assertNull($this->runner()->ask(new ShowPage($child))->publishAt, 'Not tried again every minute.');
    }

    public function testRequestsCheckOnceAMinute(): void
    {
        $this->runner()->run(new UpdatePage($this->about, ['de' => ['title' => 'Wir']]));
        $this->runner()->run(new SchedulePage($this->about, new DateTimeImmutable('-1 minute')));
        self::assertSame('miss', $this->request('/ueber-uns')->getHeaderLine(PageCache::HEADER));
        self::assertSame(PageStatus::Published, $this->runner()->ask(new ShowPage($this->about))->status, 'Published before the page was rendered.');

        $this->runner()->run(new UpdatePage($this->about, ['de' => ['title' => 'Uns']]));
        $this->runner()->run(new SchedulePage($this->about, new DateTimeImmutable('-1 minute')));
        $this->request('/ueber-uns');
        self::assertSame(PageStatus::Changed, $this->runner()->ask(new ShowPage($this->about))->status, 'Checked a moment ago.');

        touch($this->get(ProjectPaths::class)->var('cache/schedule'), time() - Scheduler::INTERVAL - 1);
        $this->request('/ueber-uns');
        self::assertSame(PageStatus::Published, $this->runner()->ask(new ShowPage($this->about))->status);
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

    /**
     * @param array<string, string> $headers
     */
    private function request(string $path, array $headers = [], string $host = 'example.org'): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://' . $host . $path, $headers));
    }

    private function assertGone(string $path): void
    {
        try {
            $this->request($path);
            self::fail(\sprintf('%s was answered.', $path));
        } catch (HttpException $error) {
            self::assertSame(410, $error->status, $path);
        }
    }
}
