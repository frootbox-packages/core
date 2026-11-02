<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Plugin;

use DateTimeImmutable;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Navigation\MenuItem;
use Frootbox\Core\Navigation\Menus;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Schedule\ScheduledTypes;
use Frootbox\Core\Schedule\Scheduler;
use Frootbox\Core\Tests\Fixtures\Plugin\PublishItems;
use Frootbox\Core\Tests\Fixtures\Plugin\SaveItem;
use Frootbox\Core\Tests\Fixtures\Plugin\UnpublishItem;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\AddressSync;
use Frootbox\Core\Url\BasePages;
use Frootbox\Core\Url\SubpageSources;
use Frootbox\Core\Url\UrlResolver;
use Frootbox\Core\Url\Urls;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(AddressSync::class)]
#[CoversClass(BasePages::class)]
#[CoversClass(SubpageSources::class)]
#[CoversClass(UrlResolver::class)]
#[CoversClass(Scheduler::class)]
#[CoversClass(Menus::class)]
#[CoversClass(ScheduledTypes::class)]
final class SubpagesTest extends NotesTestCase
{
    private const string ITEM = '01JPLUGINITEM0000000000001';

    private string $start;

    private string $news;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->page(null, 'Start');
        $this->news = $this->page($this->start, 'News', true);
        $this->runner()->run(new PublishPages([$this->start, $this->news]));
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'neue-sorten']));
        $this->runner()->run(new PublishItems([self::ITEM]));
    }

    public function testShowsAnItemBelowItsBasePage(): void
    {
        $response = $this->request('/news/neue-sorten');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['Start', false], ['News', false], ['Neue Sorten', true]], array_map(static fn(MenuItem $item): array => [$item->label, $item->current], $this->get(Menus::class)->breadcrumb()), 'The way ends with the item.');
        self::assertStringContainsString('<title>Neue Sorten – Testsite</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/news/neue-sorten">', $html);
        self::assertMatchesRegularExpression('~<article class="item"><h\d>Neue Sorten</h\d></article>~', $html, 'The block shows the item.');
        self::assertStringContainsString('<ul class="items"></ul>', (string) $this->request('/news')->getBody(), 'The list on the page itself.');
        self::assertSame('/news/neue-sorten', $this->get(Urls::class)->path('acme/plugin/item:' . self::ITEM, 'de'));
        self::assertSame(404, $this->statusOf('/news/alte-sorten'));
        self::assertSame(404, $this->statusOf('/neue-sorten'), 'Only below the base page.');
    }

    public function testRedirectsWhenThePathOrTheBasePageChanges(): void
    {
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'sorten-2026']));
        $this->runner()->run(new PublishItems([self::ITEM]));
        self::assertSame(['/news/sorten-2026', 301], $this->redirect('/news/neue-sorten'));

        $this->runner()->run(new UpdatePage($this->news, ['de' => ['slug' => 'aktuelles']]));
        $this->runner()->run(new PublishPages([$this->news]));
        self::assertSame(200, $this->statusOf('/aktuelles/sorten-2026'), 'The paths follow the base page at once.');
        self::assertSame(['/aktuelles/sorten-2026', 301], $this->redirect('/news/sorten-2026'));
        self::assertSame(['/aktuelles/sorten-2026', 301], $this->redirect('/news/neue-sorten'));

        $this->runner()->run(new UnpublishItem(self::ITEM));
        self::assertSame(410, $this->statusOf('/aktuelles/sorten-2026'), 'Offline: gone.');
    }

    public function testRealPagesComeFirstAndTheFirstBasePageWins(): void
    {
        $real = $this->page($this->news, 'Neue Sorten');
        $second = $this->page($this->start, 'Archiv', true);
        $this->runner()->run(new PublishPages([$real, $second]));

        self::assertStringNotContainsString('class="item"', (string) $this->request('/news/neue-sorten')->getBody(), 'The real page.');
        self::assertSame(404, $this->statusOf('/archiv/neue-sorten'), 'Only below the first base page in the tree.');
    }

    public function testTheSchedulerPublishesItemsOfPlugins(): void
    {
        $item = '01JPLUGINITEM0000000000002';
        $this->runner()->run(new SaveItem($item, ['de' => 'Später'], ['de' => 'spaeter'], new DateTimeImmutable('+1 hour')));
        self::assertSame(404, $this->statusOf('/news/spaeter'));

        $result = $this->get(Scheduler::class)->run(new DateTimeImmutable('+2 hours'));

        self::assertSame([$item], $result->published);
        self::assertSame([], $result->failed);
        self::assertSame(200, $this->statusOf('/news/spaeter'));
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'plugin');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function page(?string $parentId, string $title, bool $items = false): string
    {
        $content = ['fields' => ['heading' => $title], 'areas' => ['main' => $items ? [['block' => 'acme/plugin/Items']] : []]];

        return $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => $title, 'content' => $content]], $parentId))->id;
    }

    private function request(string $path): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org' . $path));
    }

    private function statusOf(string $path): int
    {
        try {
            return $this->request($path)->getStatusCode();
        } catch (HttpException $error) {
            return $error->status;
        }
    }

    /**
     * @return array{string, int}
     */
    private function redirect(string $path): array
    {
        $response = $this->request($path);

        return [$response->getHeaderLine('Location'), $response->getStatusCode()];
    }
}
